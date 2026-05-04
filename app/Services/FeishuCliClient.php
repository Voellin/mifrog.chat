<?php

namespace App\Services;

use App\Services\FeishuCli\FeishuCliAuth;
use App\Services\FeishuCli\FeishuCliBinary;
use App\Services\FeishuCli\FeishuCliDeviceFlow;
use App\Services\FeishuCli\FeishuCliHome;
use App\Services\FeishuCli\FeishuCliProcess;

/**
 * lark-cli Feishu client — thin Facade over 5 collaborators.
 *
 * Original 1375-line implementation was split in P1.4 Strangler refactor
 * (2026-04-21). Public signatures are byte-identical to pre-split; all
 * behavior lives in App\Services\FeishuCli\*.
 *
 * Split rationale:
 *   Binary     — lark-cli binary presence, availability cache, health
 *   Home       — CLI $HOME dirs, config.json writers, legacy migration
 *   Auth       — auth status, token context cache, user scope resolution
 *   Process    — runApiCall, runApiCallViaHome, runSkillCommand + concurrency
 *   DeviceFlow — lark-cli auth login --no-wait / --device-code
 *
 * Public API consumers (27+ callers across Jobs/Controllers/Commands/
 * TaskServices/Feishu domain services) require no changes — the 20
 * public methods on this facade delegate verbatim.
 */
class FeishuCliClient
{
    public function __construct(
        private readonly FeishuCliBinary $binary,
        private readonly FeishuCliHome $home,
        private readonly FeishuCliAuth $auth,
        private readonly FeishuCliProcess $process,
        private readonly FeishuCliDeviceFlow $deviceFlow,
    ) {}

    // ─── Binary layer ────────────────────────────────────────────

    public function isEnabled(): bool
    {
        return $this->binary->isEnabled();
    }

    public function isAvailable(): bool
    {
        return $this->binary->isAvailable();
    }

    public function clearBinaryCache(): void
    {
        $this->binary->clearBinaryCache();
    }

    /**
     * @return array{enabled: bool, available: bool, version: string|null, error: string|null}
     */
    public function healthCheck(): array
    {
        return $this->binary->healthCheck();
    }

    // ─── Home layer ──────────────────────────────────────────────

    public function cliHomePath(): string
    {
        return $this->home->cliHomePath();
    }

    public function userCliHomePath(string $userKey): string
    {
        return $this->home->userCliHomePath($userKey);
    }

    /**
     * @param  array<string,mixed>  $feishuConfig
     */
    public function ensureCliHome(array $feishuConfig, string $userKey = ''): string
    {
        return $this->home->ensureCliHome($feishuConfig, $userKey);
    }

    /**
     * @param  array<string,mixed>  $feishuConfig
     * @return array<string,string>
     */
    public function buildUserEnv(array $feishuConfig, string $userKey = ''): array
    {
        return $this->home->buildUserEnv($feishuConfig, $userKey);
    }

    // ─── Auth layer ──────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $context
     */
    public function rememberTokenContext(string $accessToken, array $context): void
    {
        $this->auth->rememberTokenContext($accessToken, $context);
    }

    /**
     * @param  array<string,mixed>  $feishuConfig
     */
    public function hasVerifiedUserAuth(array $feishuConfig, string $userKey): bool
    {
        return $this->auth->hasVerifiedUserAuth($feishuConfig, $userKey);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function readAuthStatus(string $userKey = ''): ?array
    {
        return $this->auth->readAuthStatus($userKey);
    }

    // ─── Process layer ───────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $feishuConfig
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function callBotApi(array $feishuConfig, string $method, string $uri, array $options = []): array
    {
        return $this->process->callBotApi($feishuConfig, $method, $uri, $options);
    }

    /**
     * @param  array<string,mixed>  $feishuConfig
     * @return array<string,mixed>
     */
    public function downloadBotResource(array $feishuConfig, string $method, string $uri, string $outputPath): array
    {
        return $this->process->downloadBotResource($feishuConfig, $method, $uri, $outputPath);
    }

    /**
     * @param  array<string,mixed>  $feishuConfig
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function callUserApi(
        array $feishuConfig,
        string $accessToken,
        string $method,
        string $uri,
        array $options = [],
        string $userKey = ''
    ): array {
        return $this->process->callUserApi($feishuConfig, $accessToken, $method, $uri, $options, $userKey);
    }

    /**
     * @param  array<string,mixed>  $feishuConfig
     * @param  array<string>        $command
     * @return array<string,mixed>
     */
    public function runSkillCommand(
        array $feishuConfig,
        string $accessToken,
        array $command,
        string $as = 'bot',
        string $userKey = ''
    ): array {
        return $this->process->runSkillCommand($feishuConfig, $accessToken, $command, $as, $userKey);
    }

    // ─── Device Flow layer ───────────────────────────────────────

    /**
     * @param  string[]  $capabilities
     * @return string[]
     */
    public function capabilitiesToDomains(array $capabilities): array
    {
        return $this->deviceFlow->capabilitiesToDomains($capabilities);
    }

    /**
     * @param  array<string,mixed>  $feishuConfig
     * @param  string[]             $domains
     * @return array{ok:bool, verification_url?:string, device_code?:string, expires_in?:int, error?:string}
     */
    public function initiateDeviceFlow(array $feishuConfig, array $domains = ['docs', 'drive', 'im'], string $userKey = ''): array
    {
        return $this->deviceFlow->initiateDeviceFlow($feishuConfig, $domains, $userKey);
    }

    /**
     * @param  array<string,mixed>  $feishuConfig
     * @return array{ok:bool, cli_output?:array, user_info?:array|null, timed_out?:bool, error?:string}
     */
    public function completeDeviceFlow(array $feishuConfig, string $deviceCode, int $timeout = 80, string $userKey = ''): array
    {
        return $this->deviceFlow->completeDeviceFlow($feishuConfig, $deviceCode, $timeout, $userKey);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function extractLatestUserFromConfig(string $configDir): ?array
    {
        return $this->deviceFlow->extractLatestUserFromConfig($configDir);
    }

    /**
     * @return array{ok:bool, cli_output?:array, user_info?:array|null, timed_out?:bool, error?:string}
     */
    public function pollDeviceFlowCompletion(string $deviceCode, int $timeout = 80, string $userKey = ''): array
    {
        return $this->deviceFlow->pollDeviceFlowCompletion($deviceCode, $timeout, $userKey);
    }
}
