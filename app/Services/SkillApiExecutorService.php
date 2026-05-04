<?php

namespace App\Services;

use App\Models\Skill;
use App\Models\User;
use App\Exceptions\Skill\SkillConfigException;
use App\Exceptions\Skill\SkillInputException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

/**
 * HTTP executor for http_api skills.
 *
 * Responsibilities:
 *  - validate the configured URL against the blacklist + allowed schemes
 *  - map LLM-facing parameter names (e.g. 商品ID) to backend API keys (e.g. spu_id)
 *  - render URL / headers / body templates with {{api_key}} + system {{user_id}}/{{user_name}}
 *  - inject Bearer token by default (override via api_headers)
 *  - enforce timeout, forbid redirects, cap response body size
 *  - filter the response by response_visible_fields (dot-notation with [] for arrays)
 *
 * Does NOT:
 *  - resolve DNS before connecting (rebinding mitigation is handled via strict blacklist)
 *  - cache tokens (they live plaintext in skill.meta)
 *  - talk to LLM (pure HTTP + JSON)
 */
class SkillApiExecutorService
{
    /**
     * Execute the http_api skill.
     *
     * @param  array<string, mixed>  $apiConfig   Keys: url, method, headers, body_template, timeout, token, params, visible_fields
     * @param  array<string, mixed>  $paramValues LLM-supplied values keyed by either name or api_key (both accepted)
     *
     * @return array{answer: string, exit_code: int, timed_out: bool, http_status: int, model: string, input_tokens: int, output_tokens: int}
     */
    public function execute(
        Skill $skill,
        array $apiConfig,
        array $paramValues,
        User $user,
        string $runId = ''
    ): array {
        $url = trim((string) ($apiConfig['url'] ?? ''));
        if ($url === '') {
            throw new SkillConfigException("Skill /{$skill->skill_key} 未配置 api_url。");
        }

        $method = strtoupper(trim((string) ($apiConfig['method'] ?? 'POST')));
        if ($method === '') {
            $method = 'POST';
        }

        $headers = is_array($apiConfig['headers'] ?? null) ? $apiConfig['headers'] : [];
        $bodyTemplate = (string) ($apiConfig['body_template'] ?? '');
        $token = trim((string) ($apiConfig['token'] ?? ''));
        $paramsSchema = is_array($apiConfig['params'] ?? null) ? $apiConfig['params'] : [];
        $visibleFields = is_array($apiConfig['visible_fields'] ?? null) ? $apiConfig['visible_fields'] : [];

        $defaultTimeout = (int) config('mifrog.skills.http_api.default_timeout', 10);
        $maxTimeout = (int) config('mifrog.skills.http_api.max_timeout', 60);
        $timeout = (int) ($apiConfig['timeout'] ?? $defaultTimeout);
        $timeout = max(1, min($maxTimeout, $timeout));

        // 1. Map LLM-facing param names to api_keys (accept both name and api_key on input)
        $mapped = $this->mapParamValues($paramsSchema, $paramValues);

        // 2. Validate required params
        foreach ($paramsSchema as $p) {
            if (! is_array($p)) {
                continue;
            }
            $apiKey = trim((string) ($p['api_key'] ?? ''));
            if ($apiKey === '') {
                continue;
            }
            $required = (bool) ($p['required'] ?? false);
            $value = $mapped[$apiKey] ?? '';
            if ($required && (is_string($value) ? trim($value) === '' : $value === null)) {
                $label = trim((string) ($p['name'] ?? $apiKey));
                throw new SkillInputException("Skill /{$skill->skill_key} 缺少必填参数：{$label}");
            }
        }

        // 3. Build substitution map (system vars + user params)
        $systemVars = [
            'user_id' => (string) $user->id,
            'user_name' => (string) ($user->name ?? ''),
            'request' => (string) ($paramValues['__raw_request__'] ?? ''),
        ];
        $subs = array_merge($systemVars, $this->stringifyMap($mapped));

        // 4. Render URL (with URL-encoding of substituted values)
        $finalUrl = $this->renderTemplate($url, $subs, true);

        // 5. Validate URL against blacklist / scheme
        $this->assertUrlAllowed($finalUrl, $skill);

        // 6. Render headers (no URL encoding); inject default Authorization
        $finalHeaders = [];
        foreach ($headers as $k => $v) {
            $headerName = trim((string) $k);
            if ($headerName === '') {
                continue;
            }
            $finalHeaders[$headerName] = $this->renderTemplate(
                (string) $v,
                array_merge($subs, ['token' => $token]),
                false
            );
        }
        if ($token !== '' && ! $this->hasHeader($finalHeaders, 'Authorization')) {
            $finalHeaders['Authorization'] = 'Bearer ' . $token;
        }

        // 7. Build request body / query
        $requestOptions = [
            RequestOptions::HEADERS => $finalHeaders,
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::ALLOW_REDIRECTS => false,   // prevent blacklist bypass via 3xx
            RequestOptions::TIMEOUT => $timeout,
            RequestOptions::CONNECT_TIMEOUT => min(5, $timeout),
        ];

        if (in_array($method, ['GET', 'DELETE', 'HEAD'], true)) {
            // Append any api_params values that weren't consumed by URL placeholders as query string
            $consumed = $this->placeholdersIn($url);
            $query = [];
            foreach ($mapped as $apiKey => $value) {
                if (in_array($apiKey, $consumed, true)) {
                    continue;
                }
                if ($value === null || $value === '') {
                    continue;
                }
                $query[$apiKey] = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            if (! empty($query)) {
                $finalUrl .= (strpos($finalUrl, '?') === false ? '?' : '&') . http_build_query($query);
                // Re-validate because the appended query string could smuggle in a blacklisted host via param injection? No — host is fixed. Skip re-check.
            }
        } else {
            if ($bodyTemplate !== '') {
                $finalBody = $this->renderTemplate($bodyTemplate, $subs, false);
                $requestOptions[RequestOptions::BODY] = $finalBody;
            } else {
                // No body template: send mapped params as JSON
                $finalBody = json_encode($mapped, JSON_UNESCAPED_UNICODE);
                $requestOptions[RequestOptions::BODY] = $finalBody === false ? '{}' : $finalBody;
            }
            if (! $this->hasHeader($finalHeaders, 'Content-Type')) {
                $finalHeaders['Content-Type'] = 'application/json; charset=utf-8';
                $requestOptions[RequestOptions::HEADERS] = $finalHeaders;
            }
        }

        // 8. Execute
        $client = $this->buildClient();
        try {
            $response = $client->request($method, $finalUrl, $requestOptions);
            $status = (int) $response->getStatusCode();
            $bodyRaw = (string) $response->getBody();
            $contentType = strtolower($response->getHeaderLine('Content-Type'));
        } catch (GuzzleException $e) {
            $msg = $e->getMessage();
            $isTimeout = stripos($msg, 'timed out') !== false
                || stripos($msg, 'timeout') !== false
                || stripos($msg, 'cURL error 28') !== false;
            return [
                'answer' => "Skill /{$skill->skill_key} 调用内部 API 失败：" . $msg,
                'exit_code' => -1,
                'timed_out' => $isTimeout,
                'http_status' => 0,
                'model' => 'none',
                'input_tokens' => 0,
                'output_tokens' => 0,
            ];
        }

        // 9. Cap body size
        $maxBytes = (int) config('mifrog.skills.http_api.max_response_bytes', 64 * 1024);
        $truncated = false;
        if (strlen($bodyRaw) > $maxBytes) {
            $bodyRaw = substr($bodyRaw, 0, $maxBytes);
            $truncated = true;
        }

        // 10. Parse JSON if applicable and filter visible fields
        $parsed = null;
        if (str_contains($contentType, 'json') || $this->looksLikeJson($bodyRaw)) {
            $parsed = $this->tryDecodeJson($bodyRaw);
        }

        $filtered = $parsed;
        if (is_array($parsed) && ! empty($visibleFields)) {
            $filtered = $this->filterFields($parsed, $visibleFields);
        }

        $formattedBody = is_array($filtered)
            ? (json_encode($filtered, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: $bodyRaw)
            : $bodyRaw;

        if ($truncated) {
            $formattedBody .= "\n\n（响应体过大，已截断到 " . $maxBytes . " 字节。）";
        }

        $answer = $this->formatResult($skill, $status, $formattedBody);

        return [
            'answer' => $answer,
            'exit_code' => ($status >= 200 && $status < 300) ? 0 : $status,
            'timed_out' => false,
            'http_status' => $status,
            'model' => 'none',
            'input_tokens' => 0,
            'output_tokens' => 0,
        ];
    }

    /**
     * Overridable so tests can inject a mock Guzzle client.
     */
    protected function buildClient(): Client
    {
        return new Client();
    }

    /**
     * Map LLM-supplied values to backend api_keys.
     * Accepts the value keyed by either the human-facing `name` or the `api_key`.
     *
     * @param  array<int, array<string, mixed>>  $paramsSchema
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function mapParamValues(array $paramsSchema, array $input): array
    {
        $mapped = [];
        foreach ($paramsSchema as $p) {
            if (! is_array($p)) {
                continue;
            }
            $apiKey = trim((string) ($p['api_key'] ?? ''));
            if ($apiKey === '') {
                continue;
            }
            $name = trim((string) ($p['name'] ?? ''));
            if (array_key_exists($apiKey, $input)) {
                $mapped[$apiKey] = $input[$apiKey];
            } elseif ($name !== '' && array_key_exists($name, $input)) {
                $mapped[$apiKey] = $input[$name];
            }
        }

        // Also keep any extra api_keys the LLM sent that aren't in schema — callers may want to surface them
        foreach ($input as $k => $v) {
            if ($k === '__raw_request__') {
                continue;
            }
            if (! array_key_exists($k, $mapped) && is_string($k)) {
                // only include if it looks like a canonical api_key (no spaces, ASCII) to avoid leaking user name fields
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_\.]*$/', $k) === 1) {
                    $mapped[$k] = $v;
                }
            }
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $map
     * @return array<string, string>
     */
    private function stringifyMap(array $map): array
    {
        $out = [];
        foreach ($map as $k => $v) {
            if (is_scalar($v)) {
                $out[(string) $k] = (string) $v;
            } elseif (is_array($v)) {
                $out[(string) $k] = (string) json_encode($v, JSON_UNESCAPED_UNICODE);
            } else {
                $out[(string) $k] = '';
            }
        }
        return $out;
    }

    /**
     * @param  array<string, string>  $subs
     */
    private function renderTemplate(string $template, array $subs, bool $urlEncode): string
    {
        if ($template === '') {
            return '';
        }

        return preg_replace_callback(
            '/\{\{\s*([A-Za-z_\x{4e00}-\x{9fff}][A-Za-z0-9_\x{4e00}-\x{9fff}\.]*)\s*\}\}/u',
            function ($m) use ($subs, $urlEncode) {
                $key = $m[1];
                $value = $subs[$key] ?? '';
                return $urlEncode ? rawurlencode($value) : $value;
            },
            $template
        ) ?? $template;
    }

    /**
     * Collect placeholder keys present in a template string.
     *
     * @return array<int, string>
     */
    private function placeholdersIn(string $template): array
    {
        if ($template === '') {
            return [];
        }
        preg_match_all('/\{\{\s*([A-Za-z_\x{4e00}-\x{9fff}][A-Za-z0-9_\x{4e00}-\x{9fff}\.]*)\s*\}\}/u', $template, $m);
        return array_values(array_unique($m[1] ?? []));
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function hasHeader(array $headers, string $name): bool
    {
        $lower = strtolower($name);
        foreach ($headers as $k => $_v) {
            if (strtolower((string) $k) === $lower) {
                return true;
            }
        }
        return false;
    }

    /**
     * Enforce scheme + blacklist checks. Throws SkillConfigException on violation.
     */
    private function assertUrlAllowed(string $url, Skill $skill): void
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new SkillConfigException("Skill /{$skill->skill_key} 的 api_url 格式无效。");
        }

        $scheme = strtolower((string) $parts['scheme']);
        $allowedSchemes = (array) config('mifrog.skills.http_api.allowed_schemes', ['http', 'https']);
        if (! in_array($scheme, $allowedSchemes, true)) {
            throw new SkillConfigException("Skill /{$skill->skill_key} 的 api_url scheme='{$scheme}' 不被允许，只支持 " . implode('/', $allowedSchemes) . '。');
        }

        $host = strtolower((string) $parts['host']);
        $blacklist = (array) config('mifrog.skills.http_api.url_blacklist', []);
        foreach ($blacklist as $banned) {
            $banned = strtolower(trim((string) $banned));
            if ($banned === '') {
                continue;
            }
            // Treat trailing '.' as prefix match for IP ranges like "169.254."
            if (str_ends_with($banned, '.')) {
                if (str_starts_with($host, $banned)) {
                    throw new SkillConfigException("Skill /{$skill->skill_key} 的 api_url host '{$host}' 命中黑名单前缀 '{$banned}'。");
                }
                continue;
            }
            if ($host === $banned) {
                throw new SkillConfigException("Skill /{$skill->skill_key} 的 api_url host '{$host}' 被黑名单禁止。");
            }
        }
    }

    private function looksLikeJson(string $body): bool
    {
        $trimmed = ltrim($body);
        return $trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[');
    }

    /**
     * @return array<mixed>|null
     */
    private function tryDecodeJson(string $body): ?array
    {
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Filter a (possibly nested) array down to the fields listed in dot-notation paths.
     * Supports "a.b.c" and "a.b[].c" (array projection).
     *
     * @param  array<mixed>  $data
     * @param  array<int, string>  $fields
     * @return array<mixed>
     */
    private function filterFields(array $data, array $fields): array
    {
        $result = [];
        foreach ($fields as $path) {
            $path = trim((string) $path);
            if ($path === '') {
                continue;
            }
            $segments = $this->splitPath($path);
            $this->assignFromPath($result, $data, $segments, 0);
        }
        return $result;
    }

    /**
     * Split "a.b[].c" into [['key','a'],['key','b'],['proj'],['key','c']].
     *
     * @return array<int, array{0:string,1?:string}>
     */
    private function splitPath(string $path): array
    {
        $parts = preg_split('/\./', $path) ?: [];
        $segments = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (str_ends_with($part, '[]')) {
                $name = substr($part, 0, -2);
                if ($name !== '') {
                    $segments[] = ['key', $name];
                }
                $segments[] = ['proj'];
            } else {
                $segments[] = ['key', $part];
            }
        }
        return $segments;
    }

    /**
     * Walk $src along $segments and merge the terminal value into $dst at the same path.
     *
     * @param  array<mixed>  $dst
     * @param  mixed  $src
     * @param  array<int, array{0:string,1?:string}>  $segments
     */
    private function assignFromPath(array &$dst, mixed $src, array $segments, int $index): void
    {
        if ($index >= count($segments)) {
            return;
        }
        $seg = $segments[$index];

        if ($seg[0] === 'key') {
            $key = $seg[1];
            if (! is_array($src) || ! array_key_exists($key, $src)) {
                return;
            }
            $childSrc = $src[$key];
            if ($index === count($segments) - 1) {
                $dst[$key] = $childSrc;
                return;
            }
            if (! isset($dst[$key])) {
                $dst[$key] = (is_array($src[$key]) && array_is_list($src[$key])) ? [] : [];
            }
            if (! is_array($dst[$key])) {
                $dst[$key] = [];
            }
            $this->assignFromPath($dst[$key], $childSrc, $segments, $index + 1);
            return;
        }

        // 'proj' segment — iterate src as list
        if (! is_array($src)) {
            return;
        }
        foreach ($src as $i => $item) {
            if (! isset($dst[$i]) || ! is_array($dst[$i])) {
                $dst[$i] = [];
            }
            if ($index === count($segments) - 1) {
                $dst[$i] = $item;
                continue;
            }
            $this->assignFromPath($dst[$i], $item, $segments, $index + 1);
        }
    }

    private function formatResult(Skill $skill, int $status, string $body): string
    {
        if ($status >= 200 && $status < 300) {
            if (trim($body) === '') {
                return "Skill /{$skill->skill_key} 调用成功（HTTP {$status}），无响应体。";
            }
            return $body;
        }

        if ($status === 401 || $status === 403) {
            return "Skill /{$skill->skill_key} 调用被拒绝（HTTP {$status}）：请检查 api_token 是否有效、权限是否足够。\n\n响应：\n{$body}";
        }

        return "Skill /{$skill->skill_key} 调用失败（HTTP {$status}）。\n\n响应：\n{$body}";
    }
}
