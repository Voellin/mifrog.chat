<?php

namespace App\Modules\ProactiveReminder\Sources;

use App\Modules\ProactiveReminder\Contracts\ActivitySourceInterface;
use App\Modules\ProactiveReminder\DTO\ActivityItem;
use App\Modules\ProactiveReminder\DTO\ReminderScanRequest;
use App\Modules\ProactiveReminder\DTO\SourceCollectionResult;
use App\Modules\ProactiveReminder\Support\ActivityTimeParser;
use App\Services\FeishuCliClient;
use Illuminate\Support\Facades\Log;
use Throwable;

class DocumentActivitySource implements ActivitySourceInterface
{
    private const DOC_FETCH_PREVIEW_CHARS = 2000;

    public function __construct(
        private readonly FeishuCliClient $cliClient,
        private readonly ActivityTimeParser $timeParser,
    ) {
    }

    public function supports(ReminderScanRequest $request): bool
    {
        return $request->collectionMode === 'full';
    }

    public function collect(ReminderScanRequest $request, array $feishuConfig): array
    {
        $raw = $this->cliClient->runSkillCommand($feishuConfig, '', [
            'docs', '+search',
            '--as', 'user',
            '--query', '',
            '--page-size', '15',
            '--format', 'json',
        ], 'user', $request->openId);

        $sinceTs = $request->since->getTimestamp();
        $untilTs = $request->until->getTimestamp();
        $records = [];
        $items = [];

        foreach ((array) ($raw['data']['results'] ?? []) as $result) {
            if (! is_array($result)) {
                continue;
            }

            $meta = (array) ($result['result_meta'] ?? []);
            $createTime = (int) ($meta['create_time'] ?? 0);
            $updateTime = (int) ($meta['update_time'] ?? 0);
            $lastOpenTime = (int) ($meta['last_open_time'] ?? 0);

            $recentlyCreated = $createTime >= $sinceTs && $createTime <= $untilTs;
            $recentlyUpdated = $updateTime >= $sinceTs && $updateTime <= $untilTs;
            $recentlyOpened = $lastOpenTime >= $sinceTs && $lastOpenTime <= $untilTs;
            if (! $recentlyCreated && ! $recentlyUpdated && ! $recentlyOpened) {
                continue;
            }

            $owner = trim((string) ($meta['owner_name'] ?? ''));
            $ownerMatchesUser = $owner !== ''
                && mb_strtolower($owner, 'UTF-8') === mb_strtolower(trim($request->userName), 'UTF-8');

            if (! $recentlyOpened && ! $ownerMatchesUser) {
                continue;
            }

            $title = trim(strip_tags((string) ($result['title_highlighted'] ?? '')));
            $token = trim((string) ($meta['token'] ?? ''));
            $type = trim((string) ($meta['doc_types'] ?? $result['entity_type'] ?? ''));
            $preview = $this->fetchPreview($feishuConfig, $request->openId, $token, $type);
            $occurredAt = $lastOpenTime > 0 ? $lastOpenTime : ($updateTime > 0 ? $updateTime : $createTime);

            $record = [
                'title' => $title,
                'type' => $type,
                'url' => trim((string) ($meta['url'] ?? '')),
                'owner' => $owner,
                'created_at' => $meta['create_time_iso'] ?? '',
                'updated_at' => $meta['update_time_iso'] ?? '',
                'preview' => $preview,
                'last_open_time' => $lastOpenTime,
                'recently_created' => $recentlyCreated,
                'recently_updated' => $recentlyUpdated,
                'recently_opened' => $recentlyOpened,
                'owner_matches_user' => $ownerMatchesUser,
            ];
            $records[] = $record;
            $items[] = new ActivityItem(
                'document',
                'feishu.docs',
                $title,
                $preview !== '' ? mb_substr($preview, 0, 200) : $title,
                $this->timeParser->parse($occurredAt),
                $owner,
                $record
            );
        }

        return [new SourceCollectionResult('documents', $records, $items)];
    }

    private function fetchPreview(array $feishuConfig, string $userKey, string $token, string $docType): string
    {
        if ($token === '') {
            return '';
        }

        $type = strtoupper($docType);
        if (! in_array($type, ['DOCX', 'DOC', 'WIKI'], true)) {
            return '';
        }

        try {
            $fetchToken = $token;

            // Wiki entries wrap a real cloud-doc; resolve to the underlying obj_token
            // before asking docs +fetch for the content.
            if ($type === 'WIKI') {
                $resolved = $this->resolveWikiToDoc($feishuConfig, $userKey, $token);
                if ($resolved === null) {
                    return '';
                }
                $fetchToken = $resolved;
            }

            $result = $this->cliClient->runSkillCommand($feishuConfig, '', [
                'docs', '+fetch',
                '--doc', $fetchToken,
                '--format', 'json',
            ], 'user', $userKey);

            $rawOutput = trim((string) ($result['_raw_output'] ?? ''));
            if ($rawOutput !== '') {
                return mb_substr($rawOutput, 0, self::DOC_FETCH_PREVIEW_CHARS);
            }

            return mb_substr(json_encode($result, JSON_UNESCAPED_UNICODE) ?: '', 0, self::DOC_FETCH_PREVIEW_CHARS);
        } catch (Throwable $e) {
            Log::debug('[ProactiveReminder] doc_preview_failed', [
                'token' => $token,
                'doc_type' => $docType,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Translate a wiki node_token to the underlying cloud-doc obj_token by
     * calling `wiki spaces get_node`. Returns null when the node cannot be
     * resolved, or when the underlying object is not a readable doc.
     */
    private function resolveWikiToDoc(array $feishuConfig, string $userKey, string $nodeToken): ?string
    {
        try {
            $payload = json_encode(['token' => $nodeToken], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (! is_string($payload)) {
                return null;
            }

            $result = $this->cliClient->runSkillCommand($feishuConfig, '', [
                'wiki', 'spaces', 'get_node',
                '--params', $payload,
            ], 'user', $userKey);

            $node = (array) ($result['data']['node'] ?? []);
            $objType = strtolower(trim((string) ($node['obj_type'] ?? '')));
            $objToken = trim((string) ($node['obj_token'] ?? ''));

            if ($objToken === '') {
                return null;
            }

            // Only docx/doc are readable via docs +fetch. Other obj_types (sheet,
            // bitable, slides, mindnote, file) need different readers — skip them
            // for preview purposes.
            if (! in_array($objType, ['docx', 'doc'], true)) {
                return null;
            }

            return $objToken;
        } catch (Throwable $e) {
            Log::debug('[ProactiveReminder] wiki_resolve_failed', [
                'node_token' => $nodeToken,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
