<?php

namespace App\Services\FeishuCli;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Home / config-directory layer of FeishuCliClient (P1.4 Strangler split).
 * Owns: cliConfigRoot + per-user CLI HOME dirs, writeCliConfig, bot config dir,
 * legacy→scoped migration helpers. Also hosts readAuthStatusFromHome because
 * the legacy migration needs it and moving it to Auth would create a cycle.
 */
class FeishuCliHome
{
    public function __construct(
        private readonly FeishuCliBinary $binary,
    ) {}

    public function cliConfigRoot(): string
    {
        $root = trim((string) config('feishu_cli.config_root', storage_path('app/feishu_cli')));

        return $root !== '' ? $root : storage_path('app/feishu_cli');
    }

    /**
     * Shared CLI home directory.
     * The CLI reads config from $HOME/.lark-cli/config.json
     * and keychain from $HOME/.local/share/lark-cli/.
     */
    public function cliHomePath(): string
    {
        return $this->cliConfigRoot() . DIRECTORY_SEPARATOR . 'cli_home';
    }

    public function userCliHomePath(string $userKey): string
    {
        return $this->cliConfigRoot()
            . DIRECTORY_SEPARATOR
            . 'cli_users'
            . DIRECTORY_SEPARATOR
            . $this->normalizeUserScopeKey($userKey);
    }

    /**
     * Ensure CLI home directory structure with proper config.
     * Preserves existing users array (CLI adds entries after auth login).
     *
     * @param  array<string,mixed>  $feishuConfig
     */
    public function ensureCliHome(array $feishuConfig, string $userKey = ''): string
    {
        $normalizedUserKey = $this->normalizeUserScopeKey($userKey);
        if ($normalizedUserKey !== '') {
            $this->migrateLegacyCliHomeIfNeeded($feishuConfig, $normalizedUserKey);
            $this->syncScopedKeychainFromLegacyIfPossible($feishuConfig, $normalizedUserKey);

            return $this->ensureScopedCliHome($feishuConfig, $normalizedUserKey);
        }

        return $this->ensureLegacyCliHome($feishuConfig);
    }

    /**
     * @param  array<string,mixed>  $feishuConfig
     */
    private function ensureLegacyCliHome(array $feishuConfig): string
    {
        $home = $this->cliHomePath();
        $this->ensureCliHomeStructure($home, $feishuConfig);

        return $home;
    }

    /**
     * @param  array<string,mixed>  $feishuConfig
     */
    private function ensureScopedCliHome(array $feishuConfig, string $userKey): string
    {
        $home = $this->userCliHomePath($userKey);
        $this->ensureCliHomeStructure($home, $feishuConfig);

        return $home;
    }

    /**
     * @param  array<string,mixed>  $feishuConfig
     */
    private function ensureCliHomeStructure(string $home, array $feishuConfig): void
    {
        $configDir = $home . DIRECTORY_SEPARATOR . '.lark-cli';
        $keychainDir = $home . DIRECTORY_SEPARATOR . '.local' . DIRECTORY_SEPARATOR . 'share' . DIRECTORY_SEPARATOR . 'lark-cli';

        foreach ([$configDir, $keychainDir] as $dir) {
            if (! File::isDirectory($dir)) {
                File::makeDirectory($dir, 0700, true);
            }
        }

        $configPath = $configDir . DIRECTORY_SEPARATOR . 'config.json';

        // ONLY write config if it does not exist yet.
        // After `lark-cli auth login` the CLI updates this file with user entries,
        // keychain references, and other metadata. Overwriting would destroy that.
        if (! File::exists($configPath)) {
            $payload = [
                'apps' => [[
                    'appId' => (string) $feishuConfig['app_id'],
                    'appSecret' => (string) $feishuConfig['app_secret'],
                    'brand' => 'feishu',
                    'defaultAs' => 'user',
                    'users' => [],
                ]],
            ];

            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            File::put($configPath, $json . "\n");
            @chmod($configPath, 0644);
        }

        @chmod($configDir, 0700);
        @chmod($keychainDir, 0700);

    }

    /**
     * Build environment array for CLI processes that need user token access.
     * Uses HOME so CLI can find both config and keychain.
     *
     * @param  array<string,mixed>  $feishuConfig
     */
    public function buildUserEnv(array $feishuConfig, string $userKey = ''): array
    {
        $home = $this->ensureCliHome($feishuConfig, $userKey);
        return ['HOME' => $home];
    }

    /**
     * @param  array<string,mixed>  $feishuConfig
     */
    public function ensureBotConfigDir(array $feishuConfig): string
    {
        $configDir = $this->cliConfigRoot() . DIRECTORY_SEPARATOR . 'bot';
        $payload = [
            'apps' => [[
                'appId' => (string) $feishuConfig['app_id'],
                'appSecret' => (string) $feishuConfig['app_secret'],
                'brand' => 'feishu',
                'defaultAs' => 'bot',
                'users' => [],
            ]],
        ];
        $this->writeCliConfig($configDir, $payload);

        return $configDir;
    }

    public function normalizeUserScopeKey(string $userKey): string
    {
        $trimmed = trim($userKey);
        if ($trimmed === '') {
            return '';
        }

        $normalized = preg_replace('/[^A-Za-z0-9._-]+/', '_', $trimmed) ?? '';

        return trim($normalized, '_');
    }

    /**
     * @param  array<string,mixed>  $feishuConfig
     */
    private function migrateLegacyCliHomeIfNeeded(array $feishuConfig, string $userKey): void
    {
        $targetConfig = $this->userCliHomePath($userKey)
            . DIRECTORY_SEPARATOR
            . '.lark-cli'
            . DIRECTORY_SEPARATOR
            . 'config.json';

        if (File::exists($targetConfig)) {
            return;
        }

        $legacyHome = $this->cliHomePath();
        $legacyStatus = $this->readAuthStatusFromHome($legacyHome);
        $legacyUser = $this->normalizeUserScopeKey((string) ($legacyStatus['userOpenId'] ?? ''));
        if ($legacyUser === '' || $legacyUser !== $userKey || ! (bool) ($legacyStatus['verified'] ?? false)) {
            return;
        }

        $this->seedScopedCliHomeFromLegacy($feishuConfig, $userKey, (string) ($legacyStatus['userName'] ?? ''));
    }

    /**
     * @param  array<string,mixed>  $feishuConfig
     */
    private function seedScopedCliHomeFromLegacy(array $feishuConfig, string $userKey, string $userName = ''): void
    {
        $targetHome = $this->userCliHomePath($userKey);
        $targetConfigDir = $targetHome . DIRECTORY_SEPARATOR . '.lark-cli';

        foreach ([$targetConfigDir, $targetHome . DIRECTORY_SEPARATOR . '.local' . DIRECTORY_SEPARATOR . 'share' . DIRECTORY_SEPARATOR . 'lark-cli'] as $dir) {
            if (! File::isDirectory($dir)) {
                File::makeDirectory($dir, 0700, true);
            }
        }

        $payload = [
            'apps' => [[
                'appId' => (string) $feishuConfig['app_id'],
                'appSecret' => (string) $feishuConfig['app_secret'],
                'brand' => 'feishu',
                'defaultAs' => 'user',
                'users' => [[
                    'userOpenId' => $userKey,
                    'userName' => trim($userName),
                ]],
            ]],
        ];
        $this->writeCliConfig($targetConfigDir, $payload);

        $this->copyLegacyUserKeychainFiles($feishuConfig, $userKey);
    }

    /**
     * @param  array<string,mixed>  $feishuConfig
     */
    private function syncScopedKeychainFromLegacyIfPossible(array $feishuConfig, string $userKey): void
    {
        $legacyHome = $this->cliHomePath();
        $legacyConfigPath = $legacyHome . DIRECTORY_SEPARATOR . '.lark-cli' . DIRECTORY_SEPARATOR . 'config.json';
        if (! File::exists($legacyConfigPath)) {
            return;
        }

        $legacyStatus = $this->readAuthStatusFromHome($legacyHome);
        $legacyUser = $this->normalizeUserScopeKey((string) ($legacyStatus['userOpenId'] ?? ''));
        if ($legacyUser === '' || $legacyUser !== $userKey || ! (bool) ($legacyStatus['verified'] ?? false)) {
            return;
        }

        $this->copyLegacyUserKeychainFiles($feishuConfig, $userKey);
    }

    /**
     * @param  array<string,mixed>  $feishuConfig
     */
    private function copyLegacyUserKeychainFiles(array $feishuConfig, string $userKey): void
    {
        $targetKeychainDir = $this->userCliHomePath($userKey)
            . DIRECTORY_SEPARATOR
            . '.local'
            . DIRECTORY_SEPARATOR
            . 'share'
            . DIRECTORY_SEPARATOR
            . 'lark-cli';

        if (! File::isDirectory($targetKeychainDir)) {
            File::makeDirectory($targetKeychainDir, 0700, true);
        }

        $legacyKeychainDir = $this->cliHomePath()
            . DIRECTORY_SEPARATOR
            . '.local'
            . DIRECTORY_SEPARATOR
            . 'share'
            . DIRECTORY_SEPARATOR
            . 'lark-cli';

        $appId = (string) ($feishuConfig['app_id'] ?? '');
        $filesToCopy = array_filter([
            'master.key',
            'appsecret_' . $appId . '.enc',
            'cli_' . $appId . '_refresh.enc',
            'cli_' . $appId . '_' . $userKey . '.enc',
            'user_' . $userKey . '_access.enc',
            'user_' . $userKey . '_refresh.enc',
        ]);

        foreach ($filesToCopy as $fileName) {
            $from = $legacyKeychainDir . DIRECTORY_SEPARATOR . $fileName;
            $to = $targetKeychainDir . DIRECTORY_SEPARATOR . $fileName;

            if (File::exists($from) && ! File::exists($to)) {
                File::copy($from, $to);
                @chmod($to, 0600);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $configPayload
     */
    public function writeCliConfig(string $configDir, array $configPayload): void
    {
        if (! File::isDirectory($configDir)) {
            File::makeDirectory($configDir, 0700, true);
        }

        $json = json_encode($configPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (! is_string($json)) {
            throw new \RuntimeException('failed_to_encode_cli_config');
        }

        $configPath = $configDir . DIRECTORY_SEPARATOR . 'config.json';
        File::put($configPath, $json . "\n");

        // Restrict permissions — config.json contains app_secret
        @chmod($configPath, 0644);
        @chmod($configDir, 0700);
    }

    public function readAuthStatusFromHome(string $cliHome): ?array
    {
        $configPath = $cliHome . DIRECTORY_SEPARATOR . '.lark-cli' . DIRECTORY_SEPARATOR . 'config.json';
        if (! File::exists($configPath)) {
            return null;
        }

        try {
            $process = new Process(
                [$this->binary->binaryPath(), 'auth', 'status', '--verify'],
                base_path(),
                ['HOME' => $cliHome],
                null,
                30
            );
            $process->run();

            $stdout = trim($process->getOutput());
            if ($stdout === '' || ! $process->isSuccessful()) {
                return null;
            }

            return json_decode($stdout, true) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
