<?php

namespace App\Services\FeishuCli;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Device Flow layer of FeishuCliClient (P1.4 Strangler split).
 * Owns: capability→domain mapping, lark-cli auth login --no-wait /
 * --device-code invocations, and the per-user CLI config parse helper.
 */
class FeishuCliDeviceFlow
{
    public function __construct(
        private readonly FeishuCliBinary $binary,
        private readonly FeishuCliHome $home,
    ) {}

    /**
     * Map missing capability prefixes to lark-cli --domain values.
     *
     * @param  string[]  $capabilities  e.g. ['feishu.scope.docx:document', 'feishu.scope.im:message:readonly']
     * @return string[]  e.g. ['docs', 'drive', 'im']
     */
    public function capabilitiesToDomains(array $capabilities): array
    {
        $map = [
            'docx:'     => 'docs',
            'docs:'     => 'docs',
            'drive:'    => 'drive',
            'im:'       => 'im',
            'search:'   => 'im',
            'calendar:' => 'calendar',
            'contact:'  => 'contact',
            'mail:'     => 'mail',
            'sheets:'   => 'sheets',
            'wiki:'     => 'wiki',
            'task:'     => 'task',
            'vc:'       => 'vc',
            'approval:' => 'approval',
            'base:'     => 'base',
            'event:'    => 'event',
            'minutes:'  => 'minutes',
            'meeting:'  => 'calendar',
        ];

        $domains = [];
        foreach ($capabilities as $cap) {
            $lower = strtolower(trim((string) $cap));
            $lower = preg_replace('/^feishu\.scope\./', '', $lower) ?? $lower;
            foreach ($map as $prefix => $domain) {
                if (str_starts_with($lower, $prefix)) {
                    $domains[$domain] = true;
                    break;
                }
            }
        }

        return array_keys($domains) ?: ['docs', 'drive', 'im'];
    }

    /**
     * Start a Device Flow authorization.
     *
     * @param  array<string,mixed>  $feishuConfig  from FeishuService::readConfig()
     * @param  string[]             $domains       lark-cli --domain values
     * @return array{ok:bool, verification_url?:string, device_code?:string, expires_in?:int, error?:string}
     */
    public function initiateDeviceFlow(array $feishuConfig, array $domains = ['docs', 'drive', 'im'], string $userKey = ''): array
    {
        try {
            $cliHome = $this->home->ensureCliHome($feishuConfig, $userKey);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'config_setup_failed: '.$e->getMessage()];
        }

        $args = [
            $this->binary->binaryPath(),
            'auth', 'login',
            '--no-wait',
            '--domain', implode(',', $domains),
            '--json',
        ];

        try {
            $process = new Process($args, base_path(), [
                'HOME' => $cliHome,
            ], null, 30);
            $process->run();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'process_error: '.$e->getMessage()];
        }

        $stdout = trim($process->getOutput());
        $result = json_decode($stdout, true);

        if (! is_array($result)) {
            Log::warning('[FeishuCliClient] Device flow decode failed', [
                'stdout' => $stdout,
                'stderr' => trim($process->getErrorOutput()),
                'exit'   => $process->getExitCode(),
            ]);
            return ['ok' => false, 'error' => 'decode_failed'];
        }

        $verificationUrl = trim((string) ($result['verification_url'] ?? ''));
        $deviceCode      = trim((string) ($result['device_code'] ?? ''));

        if ($verificationUrl === '' || $deviceCode === '') {
            Log::warning('[FeishuCliClient] Device flow missing fields', $result);
            return ['ok' => false, 'error' => trim((string) ($result['error']['message'] ?? 'missing_fields'))];
        }

        return [
            'ok'               => true,
            'verification_url' => $verificationUrl,
            'device_code'      => $deviceCode,
            'expires_in'       => (int) ($result['expires_in'] ?? 600),
        ];
    }

    /**
     * Block until the user completes Device Flow authorization (or timeout).
     *
     * @param  array<string,mixed>  $feishuConfig
     * @return array{ok:bool, cli_output?:array, user_info?:array|null, timed_out?:bool, error?:string}
     */
    public function completeDeviceFlow(array $feishuConfig, string $deviceCode, int $timeout = 80, string $userKey = ''): array
    {
        try {
            $cliHome = $this->home->ensureCliHome($feishuConfig, $userKey);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'config_setup_failed: '.$e->getMessage()];
        }

        $args = [
            $this->binary->binaryPath(),
            'auth', 'login',
            '--device-code', $deviceCode,
            '--json',
        ];

        try {
            $process = new Process($args, base_path(), [
                'HOME' => $cliHome,
            ], null, $timeout);
            $process->run();
        } catch (ProcessTimedOutException $e) {
            return ['ok' => false, 'timed_out' => true, 'error' => 'poll_timeout'];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'process_error: '.$e->getMessage()];
        }

        $stdout = trim($process->getOutput());
        $stderr = trim($process->getErrorOutput());
        $exitCode = $process->getExitCode() ?? 1;

        if (! $process->isSuccessful()) {
            $lower = strtolower($stdout . ' ' . $stderr);
            $isPending = str_contains($lower, 'pending') || str_contains($lower, 'authorization_pending') || str_contains($lower, 'slow_down');
            return [
                'ok'        => false,
                'timed_out' => $isPending,
                'error'     => 'exit_'.$exitCode,
                'stderr'    => $stderr,
            ];
        }

        $cliOutput = json_decode($stdout, true) ?? [];
        $cliConfigDir = $cliHome . DIRECTORY_SEPARATOR . '.lark-cli';
        $userInfo  = $this->extractLatestUserFromConfig($cliConfigDir);

        return [
            'ok'         => true,
            'cli_output' => $cliOutput,
            'user_info'  => $userInfo,
        ];
    }

    /**
     * Read the newest user entry from the CLI config after Device Flow login.
     *
     * @return array<string,mixed>|null
     */
    public function extractLatestUserFromConfig(string $configDir): ?array
    {
        $configPath = $configDir . DIRECTORY_SEPARATOR . 'config.json';
        if (! File::exists($configPath)) {
            return null;
        }

        try {
            $config = json_decode(File::get($configPath), true);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($config)) {
            return null;
        }

        $users = (array) ($config['apps'][0]['users'] ?? []);

        return ! empty($users) ? end($users) : null;
    }

    /**
     * Public wrapper: poll device flow using the existing bot config dir.
     * Does NOT require feishuConfig — safe to call from queue jobs.
     *
     * @return array{ok:bool, cli_output?:array, user_info?:array|null, timed_out?:bool, error?:string}
     */
    public function pollDeviceFlowCompletion(string $deviceCode, int $timeout = 80, string $userKey = ''): array
    {
        $cliHome = $this->home->normalizeUserScopeKey($userKey) !== ''
            ? $this->home->userCliHomePath($userKey)
            : $this->home->cliHomePath();
        $configPath = $cliHome . DIRECTORY_SEPARATOR . '.lark-cli' . DIRECTORY_SEPARATOR . 'config.json';
        if (! File::exists($configPath)) {
            return ['ok' => false, 'error' => 'cli_home_not_configured'];
        }

        $args = [
            $this->binary->binaryPath(),
            'auth', 'login',
            '--device-code', $deviceCode,
            '--json',
        ];

        try {
            $process = new Process($args, base_path(), [
                'HOME' => $cliHome,
            ], null, $timeout);
            $process->run();
        } catch (ProcessTimedOutException $e) {
            return ['ok' => false, 'timed_out' => true, 'error' => 'poll_timeout'];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'process_error: '.$e->getMessage()];
        }

        $stdout = trim($process->getOutput());
        $exitCode = $process->getExitCode() ?? 1;

        if (! $process->isSuccessful()) {
            $lower = strtolower($stdout . ' ' . trim($process->getErrorOutput()));
            $isPending = str_contains($lower, 'pending') || str_contains($lower, 'authorization_pending') || str_contains($lower, 'slow_down');
            return ['ok' => false, 'timed_out' => $isPending, 'error' => 'exit_'.$exitCode];
        }

        $cliOutput = json_decode($stdout, true) ?? [];
        $cliConfigDir = $cliHome . DIRECTORY_SEPARATOR . '.lark-cli';
        $userInfo  = $this->extractLatestUserFromConfig($cliConfigDir);

        Log::info('[FeishuCliClient] Device flow completed', [
            'raw_stdout' => $stdout,
            'raw_stderr' => trim($process->getErrorOutput()),
            'parsed_keys' => array_keys($cliOutput),
            'user_info_keys' => is_array($userInfo) ? array_keys($userInfo) : null,
        ]);

        return ['ok' => true, 'cli_output' => $cliOutput, 'user_info' => $userInfo, 'raw_stdout' => $stdout];
    }
}
