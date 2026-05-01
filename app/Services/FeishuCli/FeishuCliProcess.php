<?php

namespace App\Services\FeishuCli;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Process / CLI execution layer of FeishuCliClient (P1.4 Strangler split).
 * Owns: lark-cli subprocess invocation (runApiCall / runApiCallViaHome /
 * runSkillCommand), error parsing & classification, and the file-lock
 * semaphore that caps concurrent lark-cli processes.
 */
class FeishuCliProcess
{
    /** Max concurrent CLI processes (file-lock based semaphore). */
    private const CONCURRENCY_LOCK_PREFIX = 'feishu_cli_slot_';

    public function __construct(
        private readonly FeishuCliBinary $binary,
        private readonly FeishuCliHome $home,
        private readonly FeishuCliAuth $auth,
    ) {}

    /**
     * @param  array<string,mixed>  $feishuConfig
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function callBotApi(array $feishuConfig, string $method, string $uri, array $options = []): array
    {
        $this->binary->ensureFeishuEnabled($feishuConfig);
        $configDir = $this->home->ensureBotConfigDir($feishuConfig);

        return $this->runApiCall(
            $configDir,
            'bot',
            $method,
            $uri,
            (array) ($options['query'] ?? []),
            $options['json'] ?? null,
            null
        );
    }

    /**
     * Bot resource download via CLI --output flag.
     *
     * @param  array<string,mixed>  $feishuConfig
     * @return array<string,mixed>
     */
    public function downloadBotResource(array $feishuConfig, string $method, string $uri, string $outputPath): array
    {
        $this->binary->ensureFeishuEnabled($feishuConfig);
        $configDir = $this->home->ensureBotConfigDir($feishuConfig);

        return $this->runApiCall($configDir, 'bot', $method, $uri, [], null, $outputPath);
    }

    /**
     * Call Feishu API as a user via CLI.
     * Writes a temporary config with the user's access_token, executes, then cleans up.
     *
     * @param  array<string,mixed>  $feishuConfig  App credentials from FeishuService::readConfig()
     * @param  string               $accessToken   User's OAuth access token
     * @param  string               $method        HTTP method
     * @param  string               $uri           API path
     * @param  array<string,mixed>  $options       ['query' => [...], 'json' => [...]]
     * @return array<string,mixed>
     */
    public function callUserApi(
        array $feishuConfig,
        string $accessToken,
        string $method,
        string $uri,
        array $options = [],
        string $userKey = ''
    ): array
    {
        $this->binary->ensureFeishuEnabled($feishuConfig);

        // Use HOME-based approach for user API calls (CLI needs keychain access)
        $cliHome = $this->home->ensureCliHome($feishuConfig, $this->auth->resolveUserScopeKey($accessToken, $userKey));

        return $this->runApiCallViaHome(
            $cliHome,
            'user',
            $method,
            $uri,
            (array) ($options['query'] ?? []),
            $options['json'] ?? null,
            null
        );
    }

    /**
     * Run a high-level lark-cli skill command (e.g., sheets +create, sheets +read).
     *
     * @param  array<string,mixed>  $feishuConfig  App credentials
     * @param  string               $accessToken   User's OAuth access token
     * @param  array<string>        $command       Command parts, e.g. ['sheets', '+create', '--title', 'My Sheet']
     * @return array<string,mixed>
     */
    public function runSkillCommand(
        array $feishuConfig,
        string $accessToken,
        array $command,
        string $as = 'bot',
        string $userKey = ''
    ): array
    {
        $this->binary->ensureFeishuEnabled($feishuConfig);

        // Use bot identity by default — bot token is auto-obtained from app credentials.
        // User identity requires CLI keychain (device flow auth), only use when explicitly needed.
        $configDir = ($as === 'user')
            ? $this->home->ensureCliHome($feishuConfig, $this->auth->resolveUserScopeKey($accessToken, $userKey))
            : $this->home->ensureBotConfigDir($feishuConfig);

        if (! $this->binary->isAvailable()) {
            throw new \RuntimeException('cli_binary_not_available: ' . $this->binary->binaryPath());
        }

        $args = array_merge([$this->binary->binaryPath()], $command, ['--as', $as]);

        $lockHandle = $this->acquireConcurrencySlot();

        $env = ($as === 'user')
            ? ['HOME' => $configDir]
            : ['LARKSUITE_CLI_CONFIG_DIR' => $configDir];

        try {
            $process = new Process(
                $args,
                base_path(),
                $env,
                null,
                $this->binary->timeoutSeconds()
            );
            $process->run();

            $stdout = trim($process->getOutput());
            $stderr = trim($process->getErrorOutput());
            $exitCode = $process->getExitCode() ?? 1;

            if (! $process->isSuccessful()) {
                return $this->handleCliFailure($exitCode, $stdout, $stderr);
            }

            if ($stdout === '') {
                return [];
            }

            $decoded = json_decode($stdout, true);
            if (is_array($decoded)) {
                // CLI may exit 0 but return ok:false — treat as failure
                if (($decoded['ok'] ?? true) === false && isset($decoded['error'])) {
                    return $this->handleCliFailure(1, $stdout, $stderr);
                }
                return $decoded;
            }

            // Some skill commands output non-JSON (like table format). Return as raw.
            return ['_raw_output' => $stdout];
        } finally {
            $this->releaseConcurrencySlot($lockHandle);
        }
    }

    /**
     * @param  array<string,mixed>  $extraQuery
     * @return array<string,mixed>
     */
    private function runApiCall(
        string $configDir,
        string $as,
        string $method,
        string $uri,
        array $extraQuery = [],
        mixed $data = null,
        ?string $outputPath = null
    ): array {
        // Pre-flight: ensure binary exists
        if (! $this->binary->isAvailable()) {
            throw new \RuntimeException('cli_binary_not_available: ' . $this->binary->binaryPath());
        }

        [$path, $uriQuery] = $this->splitUri($uri);
        $query = array_merge($uriQuery, $extraQuery);

        $args = [
            $this->binary->binaryPath(),
            'api',
            '--as',
            $as,
            strtoupper(trim($method)),
            '/open-apis/' . $path,
        ];

        if ($query !== []) {
            $queryJson = json_encode($query, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (! is_string($queryJson)) {
                throw new \RuntimeException('encode_cli_query_failed');
            }
            $args[] = '--params';
            $args[] = $queryJson;
        }

        if ($data !== null) {
            $dataJson = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (! is_string($dataJson)) {
                throw new \RuntimeException('encode_cli_body_failed');
            }
            $args[] = '--data';
            $args[] = $dataJson;
        }

        // lark-cli --output 安全策略：仅接受 cwd 之内的相对路径。
        // 解决方法：当有 outputPath 时把 Process 的 cwd 切到 outputPath 所在目录，
        // --output 改成 './basename'。绝对路径 outputPath 会被 cli reject "unsafe output path"。
        $cwd = base_path();
        if ($outputPath !== null && trim($outputPath) !== '') {
            $outputDir = dirname($outputPath);
            if (! is_dir($outputDir)) {
                @mkdir($outputDir, 0755, true);
            }
            $cwd = $outputDir;
            $args[] = '--output';
            $args[] = './' . basename($outputPath);
        }

        // Acquire a concurrency slot (file-lock semaphore)
        $lockHandle = $this->acquireConcurrencySlot();

        try {
            $process = new Process(
                $args,
                $cwd,
                [
                    'LARKSUITE_CLI_CONFIG_DIR' => $configDir,
                    'LARKSUITE_CLI_DEFAULT_AS' => $as,
                ],
                null,
                $this->binary->timeoutSeconds()
            );
            $process->run();

            $stdout = trim($process->getOutput());
            $stderr = trim($process->getErrorOutput());
            $exitCode = $process->getExitCode() ?? 1;

            if (! $process->isSuccessful()) {
                return $this->handleCliFailure($exitCode, $stdout, $stderr);
            }

            if ($stdout === '') {
                return [];
            }

            $decoded = json_decode($stdout, true);
            if (is_array($decoded)) {
                // CLI may exit 0 but return ok:false — treat as failure
                if (($decoded['ok'] ?? true) === false && isset($decoded['error'])) {
                    return $this->handleCliFailure(1, $stdout, $stderr);
                }
                return $decoded;
            }

            throw new \RuntimeException('invalid_cli_json_output: ' . mb_substr($stdout, 0, 200));
        } finally {
            $this->releaseConcurrencySlot($lockHandle);
        }
    }

    /**
     * Run API call using HOME-based env (for user token operations).
     * The CLI finds its keychain at $HOME/.local/share/lark-cli/.
     */
    private function runApiCallViaHome(
        string $cliHome,
        string $as,
        string $method,
        string $uri,
        array $extraQuery = [],
        mixed $data = null,
        ?string $outputPath = null
    ): array {
        if (! $this->binary->isAvailable()) {
            throw new \RuntimeException('cli_binary_not_available: ' . $this->binary->binaryPath());
        }

        [$path, $uriQuery] = $this->splitUri($uri);
        $query = array_merge($uriQuery, $extraQuery);

        $args = [
            $this->binary->binaryPath(),
            'api',
            '--as', $as,
            strtoupper(trim($method)),
            '/open-apis/' . $path,
        ];

        if ($query !== []) {
            $queryJson = json_encode($query, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (! is_string($queryJson)) {
                throw new \RuntimeException('encode_cli_query_failed');
            }
            $args[] = '--params';
            $args[] = $queryJson;
        }

        if ($data !== null) {
            $dataJson = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (! is_string($dataJson)) {
                throw new \RuntimeException('encode_cli_body_failed');
            }
            $args[] = '--data';
            $args[] = $dataJson;
        }

        if ($outputPath !== null && trim($outputPath) !== '') {
            $args[] = '--output';
            $args[] = $outputPath;
        }

        $lockHandle = $this->acquireConcurrencySlot();

        try {
            $process = new Process(
                $args,
                base_path(),
                ['HOME' => $cliHome],
                null,
                $this->binary->timeoutSeconds()
            );
            $process->run();

            $stdout = trim($process->getOutput());
            $stderr = trim($process->getErrorOutput());
            $exitCode = $process->getExitCode() ?? 1;

            if (! $process->isSuccessful()) {
                return $this->handleCliFailure($exitCode, $stdout, $stderr);
            }

            if ($stdout === '') {
                return [];
            }

            $decoded = json_decode($stdout, true);
            if (is_array($decoded)) {
                // CLI may exit 0 but return ok:false — treat as failure
                if (($decoded['ok'] ?? true) === false && isset($decoded['error'])) {
                    return $this->handleCliFailure(1, $stdout, $stderr);
                }
                return $decoded;
            }

            throw new \RuntimeException('invalid_cli_json_output: ' . mb_substr($stdout, 0, 200));
        } finally {
            $this->releaseConcurrencySlot($lockHandle);
        }
    }

    /**
     * Handle CLI process failure with categorized error responses.
     *
     * @return array<string,mixed>
     */
    private function handleCliFailure(int $exitCode, string $stdout, string $stderr): array
    {
        // Try to extract a structured API error from the output
        $apiErrorBody = $this->extractApiErrorBody($stdout, $stderr);
        if ($apiErrorBody !== null) {
            // Auth errors MUST throw so task services' catch blocks can detect them
            $errorType = strtolower(trim((string) ($apiErrorBody['error_type'] ?? '')));
            $msg = strtolower(trim((string) ($apiErrorBody['msg'] ?? '')));
            $isAuth = in_array($errorType, ['auth', 'token', 'permission', 'config'], true)
                || str_contains($msg, 'not logged in')
                || str_contains($msg, 'not configured')
                || str_contains($msg, 'auth')
                || str_contains($msg, '授权')
                || str_contains($msg, '权限');
            if ($isAuth) {
                throw new \RuntimeException('[auth] ' . ($apiErrorBody['msg'] ?? 'authentication_required'));
            }
            return $apiErrorBody;
        }

        // Categorize by exit code for better diagnostics
        $category = match (true) {
            $exitCode === 127 => 'command_not_found',         // Binary not in PATH
            $exitCode === 126 => 'permission_denied',          // Not executable
            $exitCode === 137 => 'killed_oom',                 // Killed by OOM / signal 9
            $exitCode === 143 => 'killed_sigterm',             // SIGTERM
            $exitCode >= 128 => 'killed_signal_' . ($exitCode - 128),
            default => 'cli_error',
        };

        // For certain categories, invalidate the binary cache
        if (in_array($category, ['command_not_found', 'permission_denied'], true)) {
            $this->binary->clearBinaryCache();
        }

        $errorMsg = $this->formatCliError($exitCode, $stdout, $stderr);

        throw new \RuntimeException("[{$category}] {$errorMsg}");
    }

    // ─── Concurrency control ─────────────────────────────────────

    /**
     * Acquire a file-lock slot. Returns the lock file handle.
     *
     * @return resource|null
     */
    private function acquireConcurrencySlot()
    {
        $max = $this->binary->maxConcurrentProcesses();
        $lockDir = $this->home->cliConfigRoot() . DIRECTORY_SEPARATOR . 'locks';

        if (! File::isDirectory($lockDir)) {
            File::makeDirectory($lockDir, 0700, true);
        }

        // Try each slot; if all locked, wait on slot 0
        for ($i = 0; $i < $max; $i++) {
            $lockFile = $lockDir . DIRECTORY_SEPARATOR . self::CONCURRENCY_LOCK_PREFIX . $i . '.lock';
            $handle = @fopen($lockFile, 'c');
            if ($handle === false) {
                continue;
            }

            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }

            fclose($handle);
        }

        // All slots taken — block on slot 0
        $lockFile = $lockDir . DIRECTORY_SEPARATOR . self::CONCURRENCY_LOCK_PREFIX . '0.lock';
        $handle = @fopen($lockFile, 'c');
        if ($handle !== false) {
            flock($handle, LOCK_EX); // blocking wait

            return $handle;
        }

        Log::warning('feishu.cli.concurrency_lock_failed');

        return null;
    }

    /**
     * Release a concurrency slot.
     *
     * @param  resource|null  $handle
     */
    private function releaseConcurrencySlot($handle): void
    {
        if ($handle !== null && is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    // ─── Error parsing ───────────────────────────────────────────

    /**
     * @return array<string,mixed>|null
     */
    private function extractApiErrorBody(string $stdout, string $stderr): ?array
    {
        $candidates = [];
        if ($stderr !== '') {
            $candidates[] = $stderr;
        }
        if ($stdout !== '') {
            $candidates[] = $stdout;
        }

        foreach ($candidates as $candidate) {
            $decoded = json_decode($candidate, true);
            if (! is_array($decoded)) {
                continue;
            }

            // lark-cli wraps errors in {"ok":false,"error":{...}}
            $error = (array) ($decoded['error'] ?? []);
            $message = trim((string) ($error['message'] ?? ''));
            $codeRaw = $error['code'] ?? null;
            if ($message === '' && ! is_numeric($codeRaw)) {
                continue;
            }

            return [
                'code' => is_numeric($codeRaw) ? (int) $codeRaw : -1,
                'msg' => $message !== '' ? $message : 'cli_api_error',
                'data' => [],
                'cli_error' => true,
                'error_type' => trim((string) ($error['type'] ?? 'api_error')),
                'error_detail' => $error['detail'] ?? null,
                'error_hint' => trim((string) ($error['hint'] ?? '')),
            ];
        }

        return null;
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private function splitUri(string $uri): array
    {
        $trimmed = trim($uri);
        $parts = parse_url($trimmed);
        $path = ltrim((string) ($parts['path'] ?? $trimmed), '/');
        $path = preg_replace('#^open-apis/#', '', $path) ?? $path;

        $query = [];
        $rawQuery = (string) ($parts['query'] ?? '');
        if ($rawQuery !== '') {
            parse_str($rawQuery, $query);
        }

        return [$path, is_array($query) ? $query : []];
    }

    private function formatCliError(int $exitCode, string $stdout, string $stderr): string
    {
        $decodedErr = json_decode($stderr, true);
        if (is_array($decodedErr)) {
            $error = (array) ($decodedErr['error'] ?? []);
            $message = trim((string) ($error['message'] ?? ''));
            $type = trim((string) ($error['type'] ?? 'cli_error'));
            $code = (string) ($error['code'] ?? '');
            if ($message !== '') {
                return sprintf('%s%s: %s', $type, $code !== '' ? '(' . $code . ')' : '', $message);
            }
        }

        $payload = trim($stderr !== '' ? $stderr : $stdout);
        if ($payload !== '') {
            return 'cli_call_failed(exit=' . $exitCode . '): ' . mb_substr($payload, 0, 300);
        }

        return 'cli_call_failed(exit=' . $exitCode . ')';
    }
}
