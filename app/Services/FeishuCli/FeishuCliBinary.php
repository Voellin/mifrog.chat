<?php

namespace App\Services\FeishuCli;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Binary / availability layer of FeishuCliClient (P1.4 Strangler split).
 * Owns: binary path resolution, availability cache, timeout/concurrency limits,
 * config sanity checks, and the standalone healthCheck entrypoint.
 */
class FeishuCliBinary
{
    /** Cache key for binary availability check. */
    private const BINARY_CHECK_CACHE_KEY = 'feishu_cli_binary_available';
    private const BINARY_CHECK_TTL = 300; // 5 minutes

    public function isEnabled(): bool
    {
        $raw = config('feishu_cli.enabled', true);
        if (is_bool($raw)) {
            return $raw;
        }

        return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Check if the CLI binary is actually available and executable.
     * Results are cached for 5 minutes to avoid repeated filesystem checks.
     */
    public function isAvailable(): bool
    {
        $cached = Cache::get(self::BINARY_CHECK_CACHE_KEY);
        if ($cached !== null) {
            return (bool) $cached;
        }

        $binary = $this->binaryPath();
        $available = false;

        if (str_contains($binary, DIRECTORY_SEPARATOR) || str_contains($binary, '/')) {
            // Absolute or relative path — check directly
            $available = is_file($binary) && is_executable($binary);
        } else {
            // Bare name — check via `which`
            $process = new Process(['which', $binary], null, null, null, 5);
            $process->run();
            $available = $process->isSuccessful() && trim($process->getOutput()) !== '';
        }

        Cache::put(self::BINARY_CHECK_CACHE_KEY, $available, now()->addSeconds(self::BINARY_CHECK_TTL));

        if (! $available) {
            Log::warning('feishu.cli.binary_not_available', ['binary' => $binary]);
        }

        return $available;
    }

    /**
     * @param  array<string,mixed>  $feishuConfig
     */
    public function ensureFeishuEnabled(array $feishuConfig): void
    {
        $appId = trim((string) ($feishuConfig['app_id'] ?? ''));
        $appSecret = trim((string) ($feishuConfig['app_secret'] ?? ''));
        if ($appId === '' || $appSecret === '') {
            throw new \RuntimeException('feishu_config_missing');
        }
    }

    public function binaryPath(): string
    {
        $binary = trim((string) config('feishu_cli.binary', 'lark-cli'));
        if ($binary === '') {
            $binary = 'lark-cli';
        }

        return $binary;
    }

    public function timeoutSeconds(): int
    {
        $timeout = (int) config('feishu_cli.timeout_seconds', 35);

        return max(5, $timeout);
    }

    public function maxConcurrentProcesses(): int
    {
        $max = (int) config('feishu_cli.max_concurrent_processes', 10);

        return max(1, min(50, $max));
    }

    /**
     * Invalidate the cached binary availability check.
     * Call this after installing/updating the CLI binary.
     */
    public function clearBinaryCache(): void
    {
        Cache::forget(self::BINARY_CHECK_CACHE_KEY);
    }

    /**
     * Health check: is CLI enabled, available, and can talk to Feishu?
     *
     * @return array{enabled: bool, available: bool, version: string|null, error: string|null}
     */
    public function healthCheck(): array
    {
        $result = [
            'enabled' => $this->isEnabled(),
            'available' => false,
            'version' => null,
            'error' => null,
        ];

        if (! $result['enabled']) {
            return $result;
        }

        $this->clearBinaryCache();
        $result['available'] = $this->isAvailable();

        if (! $result['available']) {
            $result['error'] = 'CLI binary not found or not executable: ' . $this->binaryPath();
            return $result;
        }

        try {
            $process = new Process([$this->binaryPath(), '--version'], base_path(), null, null, 10);
            $process->run();
            $result['version'] = trim($process->getOutput() ?: $process->getErrorOutput());
        } catch (Throwable $e) {
            $result['error'] = 'version_check_failed: ' . $e->getMessage();
        }

        return $result;
    }
}
