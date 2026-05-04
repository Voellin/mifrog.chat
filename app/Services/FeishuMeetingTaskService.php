<?php

namespace App\Services;

use App\Models\Run;
use Throwable;

class FeishuMeetingTaskService extends AbstractFeishuTaskService
{
    public function __construct(
        FeishuService $feishuService,
        FeishuCliClient $feishuCliClient,
    ) {
        parent::__construct($feishuService, $feishuCliClient);
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    public function execute(Run $run, array $params): array
    {
        if (($params['_extraction_failed'] ?? false) === true) {
            return [
                'status' => 'clarify',
                'message' => 'Please tell me whether you want to search meetings or read meeting notes.',
            ];
        }

        if (($params['needs_clarification'] ?? false) === true) {
            return [
                'status' => 'clarify',
                'message' => trim((string) ($params['clarification_message'] ?? '')) ?: 'Please provide a meeting keyword, time range, or a concrete meeting id so I can continue.',
            ];
        }

        if (! $this->feishuCliClient->isEnabled() || ! $this->feishuCliClient->isAvailable()) {
            return [
                'status' => 'failed',
                'message' => 'Feishu CLI is not available, so meeting operations cannot run right now.',
            ];
        }

        $config = $this->feishuService->readConfig();
        $action = strtolower(trim((string) ($params['action'] ?? 'search')));
        $command = $this->buildCommand($action, $params);
        if ($command === null) {
            return [
                'status' => 'clarify',
                'message' => 'Please provide a meeting keyword, time range, or a concrete meeting id so I can continue.',
            ];
        }

        try {
            $result = $this->feishuCliClient->runSkillCommand(
                $config,
                '',
                $command,
                'user',
                trim((string) ($run->user?->feishu_open_id ?? ''))
            );
        } catch (Throwable $e) {
            return $this->resultFromThrowable(
                $e,
                'Feishu authorization is required before I can read meeting records.',
                'Meeting operation failed'
            );
        }

        $code = (int) ($result['code'] ?? 0);
        if ($code !== 0) {
            return [
                'status' => 'failed',
                'message' => 'Meeting operation failed: ' . trim((string) ($result['msg'] ?? 'meeting_operation_failed')),
                'error' => $result,
            ];
        }

        return $this->buildSuccessResponse($action, $result);
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>|null
     */
    private function buildCommand(string $action, array $params): ?array
    {
        return match ($action) {
            'notes' => $this->buildNotesCommand($params),
            default => $this->buildSearchCommand($params),
        };
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>|null
     */
    private function buildSearchCommand(array $params): ?array
    {
        $query = trim((string) ($params['query'] ?? ''));
        $start = trim((string) ($params['start'] ?? ''));
        $end = trim((string) ($params['end'] ?? ''));
        if ($query === '' && $start === '' && $end === '') {
            return null;
        }

        $command = ['vc', '+search', '--format', 'json'];
        if ($query !== '') {
            $command[] = '--query';
            $command[] = $query;
        }
        if ($start !== '') {
            $command[] = '--start';
            $command[] = $start;
        }
        if ($end !== '') {
            $command[] = '--end';
            $command[] = $end;
        }

        return $command;
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>|null
     */
    private function buildNotesCommand(array $params): ?array
    {
        $meetingIds = $this->normalizeStringList($params['meeting_ids'] ?? []);
        $minuteTokens = $this->normalizeStringList($params['minute_tokens'] ?? []);
        $calendarEventIds = $this->normalizeStringList($params['calendar_event_ids'] ?? []);
        if ($meetingIds === [] && $minuteTokens === [] && $calendarEventIds === []) {
            return null;
        }

        $command = ['vc', '+notes', '--format', 'json'];
        if ($meetingIds !== []) {
            $command[] = '--meeting-ids';
            $command[] = implode(',', $meetingIds);
        }
        if ($minuteTokens !== []) {
            $command[] = '--minute-tokens';
            $command[] = implode(',', $minuteTokens);
        }
        if ($calendarEventIds !== []) {
            $command[] = '--calendar-event-ids';
            $command[] = implode(',', $calendarEventIds);
        }

        return $command;
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function buildSuccessResponse(string $action, array $result): array
    {
        $data = (array) ($result['data'] ?? $result);

        return match ($action) {
            'notes' => $this->buildNotesResponse($data),
            default => $this->buildSearchResponse($data),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function buildSearchResponse(array $data): array
    {
        $items = array_values(array_filter(
            (array) ($data['items'] ?? ($data['meetings'] ?? [])),
            'is_array'
        ));
        if ($items === []) {
            return [
                'status' => 'success',
                'message' => 'I did not find any matching meetings.',
                'model' => 'feishu-meeting-search',
                'raw_data' => $data,
            ];
        }

        $lines = ['I found these meetings:'];
        foreach (array_slice($items, 0, 5) as $index => $item) {
            $topic = trim((string) ($item['topic'] ?? ($item['title'] ?? 'Meeting')));
            $meetingId = trim((string) ($item['meeting_id'] ?? ($item['id'] ?? '')));
            $url = trim((string) ($item['url'] ?? ''));
            $parts = [$topic];
            if ($meetingId !== '') {
                $parts[] = 'meeting_id=' . $meetingId;
            }
            if ($url !== '') {
                $parts[] = 'url=' . $url;
            }
            $lines[] = ($index + 1) . '. ' . implode(', ', $parts);
        }

        return [
            'status' => 'success',
            'message' => implode("\n", $lines),
            'model' => 'feishu-meeting-search',
            'raw_data' => $data,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function buildNotesResponse(array $data): array
    {
        $items = array_values(array_filter(
            (array) ($data['items'] ?? ($data['notes'] ?? [])),
            'is_array'
        ));
        if ($items === []) {
            $preview = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return [
                'status' => 'success',
                'message' => 'Meeting notes query completed.' . ($preview ? "\nPreview: " . $this->truncate($preview, 800) : ''),
                'model' => 'feishu-meeting-notes',
                'raw_data' => $data,
            ];
        }

        $lines = ['I found these meeting notes:'];
        foreach (array_slice($items, 0, 5) as $index => $item) {
            $title = trim((string) ($item['title'] ?? ($item['topic'] ?? 'Meeting notes')));
            $noteUrl = trim((string) ($item['url'] ?? ($item['note_url'] ?? '')));
            $minuteToken = trim((string) ($item['minute_token'] ?? ''));
            $parts = [$title];
            if ($minuteToken !== '') {
                $parts[] = 'minute_token=' . $minuteToken;
            }
            if ($noteUrl !== '') {
                $parts[] = 'url=' . $noteUrl;
            }
            $lines[] = ($index + 1) . '. ' . implode(', ', $parts);
        }

        return [
            'status' => 'success',
            'message' => implode("\n", $lines),
            'model' => 'feishu-meeting-notes',
            'raw_data' => $data,
        ];
    }

    /**
     * @param  mixed  $value
     * @return array<int,string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (is_string($value)) {
            $items = preg_split('/[\s,]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } elseif (is_array($value)) {
            $items = $value;
        } else {
            $items = [];
        }

        $set = [];
        foreach ($items as $item) {
            $normalized = trim((string) $item);
            if ($normalized !== '') {
                $set[$normalized] = true;
            }
        }

        return array_keys($set);
    }

    /**
     * @return array<string,mixed>
     */
    private function resultFromThrowable(Throwable $e, string $authMessage, string $failurePrefix): array
    {
        $error = trim($e->getMessage());
        $scopes = $this->extractScopesFromText($error);
        if ($this->looksLikeAuthorizationError($error) || $scopes !== []) {
            $missing = ['feishu.oauth.user_token'];
            foreach ($scopes as $scope) {
                $missing[] = 'feishu.scope.' . $scope;
            }

            return [
                'status' => 'blocked',
                'message' => $authMessage,
                'missing' => array_values(array_unique($missing)),
                'error' => $error,
            ];
        }

        return [
            'status' => 'failed',
            'message' => $failurePrefix . ': ' . $this->truncate($error, 240),
            'error' => $error,
        ];
    }


    private function looksLikeAuthorizationError(string $text): bool
    {
        $text = strtolower(trim($text));
        if ($text === '') {
            return false;
        }

        return str_contains($text, '[auth]')
            || str_contains($text, 'required scope:')
            || str_contains($text, 'not logged in')
            || str_contains($text, 'login required')
            || str_contains($text, 'authorization required')
            || str_contains($text, 'authorization is required')
            || str_contains($text, 'unauthorized')
            || str_contains($text, 'invalid access token')
            || str_contains($text, 'access token expired')
            || str_contains($text, '授权')
            || str_contains($text, '未登录')
            || str_contains($text, '缺少scope');
    }

}
