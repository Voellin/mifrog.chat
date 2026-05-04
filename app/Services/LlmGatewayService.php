<?php

namespace App\Services;

use App\Models\ModelKey;
use App\Models\ModelProvider;
use App\Models\Setting;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;

class LlmGatewayService
{
    public function chat(array $messages): array
    {
        return $this->chatWithCapability($messages, 'text');
    }

    public function chatWithCapability(array $messages, string $capability = 'text'): array
    {
        [$baseUrl, $model, $apiKey] = $this->resolveGatewayConfig($capability);

        if (! $baseUrl || ! $apiKey) {
            $latestUser = collect($messages)->reverse()->firstWhere('role', 'user');
            $fallbackText = is_array($latestUser['content'] ?? null)
                ? '当前模型网关未配置完成，已收到你的请求。'
                : '当前模型网关未配置完成，我已收到你的请求：'.(string) ($latestUser['content'] ?? '');

            return [
                'content' => $fallbackText,
                'model' => $model,
                'input_tokens' => $this->estimateTokens($messages),
                'output_tokens' => $this->estimateTokens($fallbackText),
            ];
        }

        $client = new Client([
            'base_uri' => rtrim($baseUrl, '/').'/',
            'timeout' => 60,
        ]);

        $resp = $this->postWithRetry($client, 'chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $model,
                'messages' => $messages,
                'temperature' => 0.2,
            ],
        ], 'chatWithCapability:'.$capability);

        $body = json_decode((string) $resp->getBody(), true);
        $content = Arr::get($body, 'choices.0.message.content', '');

        if (is_array($content)) {
            $content = json_encode($content, JSON_UNESCAPED_UNICODE);
        }

        return [
            'content' => (string) $content,
            'model' => Arr::get($body, 'model', $model),
            'input_tokens' => (int) Arr::get($body, 'usage.prompt_tokens', $this->estimateTokens($messages)),
            'output_tokens' => (int) Arr::get($body, 'usage.completion_tokens', $this->estimateTokens((string) $content)),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @return array{content: string, tool_calls: array<int, array<string, mixed>>, model: string, input_tokens: int, output_tokens: int}
     */
    public function chatWithTools(array $messages, array $tools = [], string $capability = 'text'): array
    {
        [$baseUrl, $model, $apiKey] = $this->resolveGatewayConfig($capability);

        if (! $baseUrl || ! $apiKey) {
            return [
                'content' => 'Model gateway is not configured yet.',
                'tool_calls' => [],
                'model' => $model,
                'input_tokens' => $this->estimateTokens($messages),
                'output_tokens' => 0,
            ];
        }

        $client = new Client([
            'base_uri' => rtrim($baseUrl, '/').'/',
            'timeout' => 60,
        ]);

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.1,
        ];

        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $resp = $this->postWithRetry($client, 'chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ], 'chatWithTools:'.$capability);

        $body = json_decode((string) $resp->getBody(), true);
        $message = Arr::get($body, 'choices.0.message', []);
        $content = $message['content'] ?? '';
        $toolCalls = (array) ($message['tool_calls'] ?? []);

        if (is_array($content)) {
            $content = json_encode($content, JSON_UNESCAPED_UNICODE);
        }

        return [
            'content' => (string) $content,
            'tool_calls' => $toolCalls,
            'model' => Arr::get($body, 'model', $model),
            'input_tokens' => (int) Arr::get($body, 'usage.prompt_tokens', $this->estimateTokens($messages)),
            'output_tokens' => (int) Arr::get($body, 'usage.completion_tokens', $this->estimateTokens((string) $content)),
        ];
    }

    public function chatStream(array $messages, callable $onDelta): array
    {
        [$baseUrl, $model, $apiKey] = $this->resolveGatewayConfig('text');
        if (! $baseUrl || ! $apiKey) {
            $fallback = $this->chat($messages);
            $this->emitInChunks((string) $fallback['content'], $onDelta);

            return $fallback;
        }

        $client = new Client([
            'base_uri' => rtrim($baseUrl, '/').'/',
            'timeout' => 180,
        ]);

        try {
            $resp = $client->post('chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'text/event-stream,application/json',
                ],
                'json' => [
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => 0.2,
                    'stream' => true,
                    'stream_options' => ['include_usage' => true],
                ],
                'stream' => true,
            ]);
        } catch (GuzzleException $e) {
            Log::warning('llm.stream.request_failed', [
                'message' => $e->getMessage(),
            ]);

            $fallback = $this->chat($messages);
            $this->emitInChunks((string) $fallback['content'], $onDelta);

            return $fallback;
        }

        $contentType = strtolower($resp->getHeaderLine('Content-Type'));
        if (! str_contains($contentType, 'text/event-stream')) {
            $body = json_decode((string) $resp->getBody(), true);
            $content = (string) Arr::get($body, 'choices.0.message.content', '');
            $this->emitInChunks($content, $onDelta);

            return [
                'content' => $content,
                'model' => Arr::get($body, 'model', $model),
                'input_tokens' => (int) Arr::get($body, 'usage.prompt_tokens', $this->estimateTokens($messages)),
                'output_tokens' => (int) Arr::get($body, 'usage.completion_tokens', $this->estimateTokens($content)),
            ];
        }

        $stream = $resp->getBody();
        $buffer = '';
        $done = false;
        $content = '';
        $inputTokens = 0;
        $outputTokens = 0;
        $resolvedModel = $model;

        while (! $stream->eof() && ! $done) {
            $buffer .= $stream->read(4096);

            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newlinePos));
                $buffer = substr($buffer, $newlinePos + 1);

                if ($line === '' || ! str_starts_with($line, 'data:')) {
                    continue;
                }

                $payload = trim(substr($line, 5));
                if ($payload === '[DONE]') {
                    $done = true;
                    break;
                }

                $item = json_decode($payload, true);
                if (! is_array($item)) {
                    continue;
                }

                $resolvedModel = (string) Arr::get($item, 'model', $resolvedModel);
                $delta = (string) Arr::get($item, 'choices.0.delta.content', '');
                if ($delta !== '') {
                    $content .= $delta;
                    $onDelta($delta);
                }

                $inputTokens = (int) Arr::get($item, 'usage.prompt_tokens', $inputTokens);
                $outputTokens = (int) Arr::get($item, 'usage.completion_tokens', $outputTokens);
            }
        }

        if ($content === '') {
            $fallback = $this->chat($messages);
            $this->emitInChunks((string) $fallback['content'], $onDelta);

            return $fallback;
        }

        return [
            'content' => $content,
            'model' => $resolvedModel,
            'input_tokens' => $inputTokens > 0 ? $inputTokens : $this->estimateTokens($messages),
            'output_tokens' => $outputTokens > 0 ? $outputTokens : $this->estimateTokens($content),
        ];
    }

    /**
     * POST with retry policy (P0-3 resilience hardening).
     *
     * Retries on:
     *   - ConnectException        (TCP / DNS / connect timeout — cURL 28 etc.)
     *   - ServerException (5xx)   (Anthropic / OpenAI "server overloaded")
     *   - RequestException w/o response (transport-level failures)
     * Does NOT retry on:
     *   - ClientException (4xx)   — bad payload / auth / quota / rate-limit, never retryable here
     *   - any non-Guzzle exception
     *
     * Budget: up to 3 total attempts (1 initial + 2 retries), backoff 1s + 3s.
     * With per-call timeout 60s, worst-case wall time = 60 + 1 + 60 + 3 + 60 = 184s.
     * Supervisor worker timeout is 360s so a Run doing up to ~4 LLM calls still fits.
     *
     * @param  array<string, mixed>  $options
     */
    private function postWithRetry(
        Client $client,
        string $path,
        array $options,
        string $context,
        int $maxAttempts = 3,
        array $backoffSchedule = [1, 3]
    ): ResponseInterface {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxAttempts) {
            $attempt++;
            $reason = null;

            try {
                return $client->post($path, $options);
            } catch (ClientException $e) {
                // 4xx — never retry; surface to caller.
                throw $e;
            } catch (ConnectException $e) {
                $lastException = $e;
                $reason = 'connect_error';
            } catch (ServerException $e) {
                $lastException = $e;
                $status = $e->getResponse()?->getStatusCode();
                $reason = '5xx:'.($status !== null ? (string) $status : 'unknown');
            } catch (RequestException $e) {
                if ($e->hasResponse()) {
                    // Response present but not 4xx/5xx (rare: 3xx redirect surfaced as error).
                    // Not a known transient class — surface immediately.
                    throw $e;
                }
                $lastException = $e;
                $reason = 'transport_error';
            }

            if ($attempt >= $maxAttempts) {
                break;
            }

            $sleepSec = $backoffSchedule[$attempt - 1] ?? (int) end($backoffSchedule);
            Log::warning('llm.retry', [
                'context' => $context,
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'reason' => $reason,
                'error' => $lastException?->getMessage(),
                'sleep_seconds' => $sleepSec,
            ]);

            if ($sleepSec > 0) {
                sleep($sleepSec);
            }
        }

        Log::error('llm.retry_exhausted', [
            'context' => $context,
            'attempts' => $attempt,
            'error' => $lastException?->getMessage(),
        ]);

        /** @var \Throwable $lastException */
        throw $lastException;
    }

    /**
     * R2: 给定 model_id 路由到承载它的 provider。
     * 返回 ['base_url' => string, 'api_key' => string, 'vendor_key' => string|null,
     *      'provider_id' => int|null, 'model' => string]。
     *
     * 路由规则：
     *   1. 找出所有 active provider，按 sort_order asc, id asc
     *   2. 第一个 models[] 里包含该 model_id 的 provider 命中
     *   3. 没命中且只有一个 active provider → 返回那个唯一 provider（兼容单 vendor 现状）
     *   4. 还是没命中 → fallback 到 resolveGatewayConfig (Setting('model_gateway'))
     *
     * 这个方法是 R2 的新出口，旧 4 个 public chat 方法暂不调用它（保持行为不变）；
     * 未来下游可以渐进迁移到这个 API 实现真正的"按模型路由"。
     */
    public function resolveProviderForModel(string $modelId): array
    {
        $modelId = trim($modelId);
        if ($modelId === '') {
            [$baseUrl, $model, $apiKey] = $this->resolveGatewayConfig('text');
            return [
                'base_url' => $baseUrl,
                'api_key' => $apiKey,
                'vendor_key' => null,
                'provider_id' => null,
                'model' => $model,
            ];
        }

        $providers = ModelProvider::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $matched = null;
        foreach ($providers as $p) {
            $models = is_array($p->models) ? $p->models : [];
            foreach ($models as $row) {
                $id = trim((string) ($row['model_id'] ?? ''));
                if ($id !== '' && $id === $modelId) {
                    $matched = $p;
                    break 2;
                }
            }
        }

        // 没匹配上：单 vendor 直接用那个唯一 provider；多 vendor 落 fallback
        if ($matched === null && $providers->count() === 1) {
            $matched = $providers->first();
        }

        if ($matched !== null) {
            $keyRow = ModelKey::query()
                ->where('provider_id', $matched->id)
                ->where('is_active', true)
                ->first();
            $apiKey = trim((string) ($keyRow?->api_key ?: ''));

            // api_key 兜底：matched provider 没 active key → 看 Setting('model_gateway')
            if ($apiKey === '') {
                $gateway = Setting::read('model_gateway', []);
                $apiKey = trim((string) Arr::get($gateway, 'api_key', ''));
            }

            return [
                'base_url' => trim((string) $matched->base_url),
                'api_key' => $apiKey,
                'vendor_key' => $matched->vendor_key,
                'provider_id' => (int) $matched->id,
                'model' => $modelId,
            ];
        }

        // 全无匹配：fallback 到旧逻辑（Setting('model_gateway') 是权威）
        [$baseUrl, $model, $apiKey] = $this->resolveGatewayConfig('text');
        return [
            'base_url' => $baseUrl,
            'api_key' => $apiKey,
            'vendor_key' => null,
            'provider_id' => null,
            'model' => $modelId !== '' ? $modelId : $model,
        ];
    }

    /**
     * R2: 改造为"优先读 model_providers 表"，但保持 Setting('model_gateway') 作为兼容回退。
     * 4 个旧 public chat 方法仍调用此方法，行为对外不变。
     *
     * 单 vendor（线上现状）：表里有 1 个 active provider，且其 models/defaults 已被 R1 backfill，
     * 所以读表得到的 base_url / api_key / model 跟读 Setting 完全一致。
     *
     * 多 vendor（未来）：取 sort_order asc, id asc 的第一个 active provider 作为"当前默认 vendor"
     * （供旧 caller 使用），新 caller 应改用 resolveProviderForModel(string $modelId)。
     */
    private function resolveGatewayConfig(?string $capability = 'text'): array
    {
        $gateway = Setting::read('model_gateway', []);
        $capability = strtolower(trim((string) $capability));
        if ($capability === '') {
            $capability = 'text';
        }

        // 1) 表优先：找 sort_order asc, id asc 的第一个 active provider
        $provider = ModelProvider::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
        $keyRow = $provider
            ? ModelKey::query()->where('provider_id', $provider->id)->where('is_active', true)->first()
            : null;

        // 2) base_url / api_key：表 > Setting fallback
        $baseUrl = trim((string) ($provider?->base_url ?: Arr::get($gateway, 'base_url', '')));
        $apiKey = trim((string) ($keyRow?->api_key ?: Arr::get($gateway, 'api_key', '')));

        // 3) model 选择优先级（沿用旧顺序，但数据源切到表的 defaults/models 优先）：
        //    provider.defaults[capability] > provider.models[].first(capability)
        //    > Setting.defaults[capability] > Setting.models[].first(capability)
        //    > Setting.defaults.text > Setting.model > provider.default_model > Setting.models[].first(text)
        //    > 'gpt-4o-mini'
        $providerDefaults = is_array($provider?->defaults ?? null) ? $provider->defaults : [];
        $providerModels = is_array($provider?->models ?? null) ? $provider->models : [];
        $settingDefaults = (array) Arr::get($gateway, 'defaults', []);
        $settingModels = (array) Arr::get($gateway, 'models', []);

        $providerCapDefault = trim((string) ($providerDefaults[$capability] ?? ''));
        $providerFirstCapModel = $this->firstModelByCapability($providerModels, $capability);
        $settingCapDefault = trim((string) ($settingDefaults[$capability] ?? ''));
        $settingFirstCapModel = $this->firstModelByCapability($settingModels, $capability);
        $settingTextDefault = trim((string) ($settingDefaults['text'] ?? Arr::get($gateway, 'model', '')));
        $legacyModel = trim((string) Arr::get($gateway, 'model', ''));
        $providerDefaultText = trim((string) ($provider?->default_model ?? ''));
        $settingFirstTextModel = $this->firstModelByCapability($settingModels, 'text');

        $model = $providerCapDefault
            ?: $providerFirstCapModel
            ?: $settingCapDefault
            ?: $settingFirstCapModel
            ?: $settingTextDefault
            ?: $legacyModel
            ?: $providerDefaultText
            ?: $settingFirstTextModel
            ?: 'gpt-4o-mini';

        return [$baseUrl, $model, $apiKey];
    }

    private function firstModelByCapability(array $models, string $capability): string
    {
        $capability = strtolower(trim($capability));
        foreach ($models as $row) {
            $cap = strtolower(trim((string) Arr::get($row, 'capability', '')));
            $id = trim((string) Arr::get($row, 'model_id', ''));
            if ($cap === $capability && $id !== '') {
                return $id;
            }
        }

        foreach ($models as $row) {
            $id = trim((string) Arr::get($row, 'model_id', ''));
            if ($id !== '') {
                return $id;
            }
        }

        return '';
    }

    private function emitInChunks(string $content, callable $onDelta): void
    {
        foreach ($this->chunkText($content, 120) as $chunk) {
            $onDelta($chunk);
        }
    }

    private function chunkText(string $content, int $chunkLength): array
    {
        if ($content === '') {
            return [];
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            $total = mb_strlen($content, 'UTF-8');
            $parts = [];
            for ($offset = 0; $offset < $total; $offset += $chunkLength) {
                $parts[] = mb_substr($content, $offset, $chunkLength, 'UTF-8');
            }

            return $parts;
        }

        return str_split($content, $chunkLength);
    }

    private function estimateTokens(array|string $payload): int
    {
        $text = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : $payload;

        return max(1, (int) ceil(strlen((string) $text) / 4));
    }
}
