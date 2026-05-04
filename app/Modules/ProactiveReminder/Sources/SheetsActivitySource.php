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

/**
 * Collects Feishu Sheets (电子表格) activity within the scan window.
 * Uses `drive files.list` ordered by EditedTime, filters to type=sheet.
 */
class SheetsActivitySource implements ActivitySourceInterface
{
    private const MAX_PAGES = 4;
    private const PAGE_SIZE = 50;

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
        $sinceTs = $request->since->getTimestamp();
        $untilTs = $request->until->getTimestamp();

        $records = [];
        $items = [];
        $pageToken = null;
        $pages = 0;
        $stopEarly = false;

        while ($pages < self::MAX_PAGES && ! $stopEarly) {
            try {
                $params = [
                    'page_size'  => self::PAGE_SIZE,
                    'order_by'   => 'EditedTime',
                    'direction'  => 'DESC',
                ];
                if ($pageToken) {
                    $params['page_token'] = $pageToken;
                }

                $raw = $this->cliClient->runSkillCommand($feishuConfig, '', [
                    'drive', 'files', 'list',
                    '--params', json_encode($params, JSON_UNESCAPED_UNICODE),
                ], 'user', $request->openId);
            } catch (Throwable $e) {
                Log::debug('[ProactiveReminder] sheets_list_failed', [
                    'page' => $pages,
                    'error' => $e->getMessage(),
                ]);
                break;
            }

            $files = (array) ($raw['data']['files'] ?? []);
            if ($files === []) {
                break;
            }

            foreach ($files as $file) {
                if (! is_array($file)) {
                    continue;
                }
                $type = strtolower(trim((string) ($file['type'] ?? '')));
                if ($type !== 'sheet') {
                    continue;
                }

                $modifiedAt = (int) ($file['modified_time'] ?? 0);
                $createdAt  = (int) ($file['created_time'] ?? 0);
                $inWindow = ($modifiedAt >= $sinceTs && $modifiedAt <= $untilTs)
                         || ($createdAt  >= $sinceTs && $createdAt  <= $untilTs);

                if (! $inWindow) {
                    // Because results are DESC by edit time, we can stop once we fall
                    // below the window lower bound (modified_time is oldest relevant mark).
                    if ($modifiedAt > 0 && $modifiedAt < $sinceTs) {
                        $stopEarly = true;
                        break;
                    }
                    continue;
                }

                $title = trim((string) ($file['name'] ?? '未命名表格'));
                $token = trim((string) ($file['token'] ?? ''));
                $url   = trim((string) ($file['url'] ?? ''));
                $owner = trim((string) ($file['owner_id'] ?? ''));
                $ownerMatchesUser = $owner !== '' && $owner === trim($request->openId);

                $record = [
                    'title'             => $title,
                    'token'             => $token,
                    'url'               => $url,
                    'owner_id'          => $owner,
                    'owner_matches_user' => $ownerMatchesUser,
                    'created_at'        => $createdAt > 0 ? date(DATE_ATOM, $createdAt) : '',
                    'modified_at'       => $modifiedAt > 0 ? date(DATE_ATOM, $modifiedAt) : '',
                ];
                $records[] = $record;

                $items[] = new ActivityItem(
                    'sheet',
                    'feishu.sheets',
                    $title,
                    $title,
                    $this->timeParser->parse($modifiedAt ?: $createdAt),
                    $owner,
                    $record
                );
            }

            $pageToken = $raw['data']['next_page_token'] ?? null;
            $hasMore = (bool) ($raw['data']['has_more'] ?? false);
            if (! $hasMore || ! $pageToken) {
                break;
            }
            $pages++;
        }

        return [new SourceCollectionResult('sheets', $records, $items)];
    }
}
