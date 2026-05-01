<?php

namespace App\Services;

use App\Models\Run;
use Throwable;

/**
 * Shared base for all Feishu/Lark task services.
 *
 * Inheriting services reuse the protected helpers below instead of
 * duplicating them in every class. Concrete subclasses remain free to
 * keep their domain-specific public entry points (execute, readHistory,
 * createEvent, ...); this abstract class only unifies infrastructure
 * concerns (clarify/blocked response shaping, CLI availability probe,
 * scope extraction, truncation).
 *
 * Introduced 2026-04-21 as part of P1.1 refactor. See FEISHU_SERVICES_BOUNDARY.md
 * for the rules governing FeishuService (the collaborator injected below);
 * this class does NOT extend that boundary — it only reduces duplication
 * across task-service subclasses.
 */
abstract class AbstractFeishuTaskService
{
    public function __construct(
        protected readonly FeishuService $feishuService,
        protected readonly FeishuCliClient $feishuCliClient,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    protected function clarifyResponse(string $message): array
    {
        return [
            'status' => 'clarify',
            'message' => $message,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function cliUnavailableResponse(string $message): array
    {
        return [
            'status' => 'failed',
            'message' => $message,
        ];
    }

    protected function isFeishuCliReady(): bool
    {
        return $this->feishuCliClient->isEnabled() && $this->feishuCliClient->isAvailable();
    }

    /**
     * Build the standard 'blocked' response from a thrown CLI/HTTP error,
     * attaching required-scope hints parsed out of the error message.
     *
     * @return array<string,mixed>
     */
    protected function blockedFromThrowable(Throwable $e, string $message): array
    {
        $error = trim($e->getMessage());
        $missing = ['feishu.oauth.user_token'];

        foreach ($this->extractScopesFromText($error) as $scope) {
            $missing[] = 'feishu.scope.' . $scope;
        }

        return [
            'status' => 'blocked',
            'message' => $message,
            'missing' => array_values(array_unique($missing)),
            'error' => $error,
        ];
    }

    /**
     * Parse Feishu CLI / Open API error text for required scope hints.
     * Accepts both the single-scope form ("required scope: xxx")
     * and the grouped form ("required one of these privileges: [a b c]").
     *
     * @return array<int,string>
     */
    protected function extractScopesFromText(string $text): array
    {
        if ($text === '') {
            return [];
        }

        preg_match_all('/required scope:\s*([a-z0-9:._-]+)/i', $text, $matches);
        $scopes = array_map(
            static fn ($item) => strtolower(trim((string) $item)),
            (array) ($matches[1] ?? [])
        );

        if (preg_match_all('/required one of these privileges:\s*\[([^\]]+)\]/i', $text, $privilegeMatches) === 1) {
            foreach ((array) ($privilegeMatches[1] ?? []) as $group) {
                foreach (preg_split('/[\s,]+/', (string) $group, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $scope) {
                    $scopes[] = strtolower(trim((string) $scope));
                }
            }
        }

        return array_values(array_unique(array_filter(
            $scopes,
            static fn (string $scope) => $scope !== ''
        )));
    }

    protected function resolveRunOpenId(Run $run): string
    {
        if (! $run->relationLoaded('user')) {
            return '';
        }

        return trim((string) ($run->user?->feishu_open_id ?? ''));
    }

    /**
     * UTF-8-aware truncation with "..." ellipsis. Default behaviour matches
     * the prior duplicated implementations in most task services.
     * FeishuDocsTaskService overrides this with a "maxChars - 1" variant.
     */
    protected function truncate(string $text, int $max): string
    {
        if ($text === '') {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') <= $max
                ? $text
                : mb_substr($text, 0, $max - 3, 'UTF-8') . '...';
        }

        return strlen($text) <= $max ? $text : substr($text, 0, $max - 3) . '...';
    }
}
