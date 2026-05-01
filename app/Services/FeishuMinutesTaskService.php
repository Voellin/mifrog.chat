<?php

namespace App\Services;

use App\Models\Run;
use Illuminate\Support\Facades\File;
use Throwable;

class FeishuMinutesTaskService extends AbstractFeishuTaskService
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
                'message' => 'Please provide a Feishu Minutes link or token.',
            ];
        }

        if (($params['needs_clarification'] ?? false) === true) {
            return [
                'status' => 'clarify',
                'message' => trim((string) ($params['clarification_message'] ?? '')) ?: 'Please provide a Feishu Minutes link or token.',
            ];
        }

        if (! $this->feishuCliClient->isEnabled() || ! $this->feishuCliClient->isAvailable()) {
            return [
                'status' => 'failed',
                'message' => 'Feishu CLI is not available, so Minutes operations cannot run right now.',
            ];
        }

        $config = $this->feishuService->readConfig();
        $action = strtolower(trim((string) ($params['action'] ?? 'info')));
        $command = $this->buildCommand($run, $action, $params);
        if ($command === null) {
            return [
                'status' => 'clarify',
                'message' => 'Please provide a Feishu Minutes link or token.',
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
            return $this->blockedFromThrowable($e, 'Feishu authorization is required before I can access Minutes content.');
        }

        $code = (int) ($result['code'] ?? 0);
        if ($code !== 0) {
            return [
                'status' => 'failed',
                'message' => 'Minutes operation failed: ' . trim((string) ($result['msg'] ?? 'minutes_operation_failed')),
                'error' => $result,
            ];
        }

        return $this->buildSuccessResponse($action, $result);
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>|null
     */
    private function buildCommand(Run $run, string $action, array $params): ?array
    {
        $minuteTokens = $this->resolveMinuteTokens($params);
        if ($minuteTokens === []) {
            return null;
        }

        if ($action === 'download') {
            $command = ['minutes', '+download', '--minute-tokens', implode(',', $minuteTokens), '--format', 'json'];
            if ((bool) ($params['url_only'] ?? true) === true) {
                $command[] = '--url-only';
            } else {
                $dir = storage_path('app/cli_artifacts/run_' . (int) $run->id);
                if (! File::isDirectory($dir)) {
                    File::makeDirectory($dir, 0755, true);
                }
                $output = count($minuteTokens) === 1
                    ? rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . trim((string) ($params['output_name'] ?? 'minute_media'))
                    : $dir;
                $command[] = '--output';
                $command[] = $output;
            }

            return $command;
        }

        $payload = json_encode(['minute_token' => $minuteTokens[0]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($payload)) {
            return null;
        }

        return ['minutes', 'minutes', 'get', '--params', $payload];
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function buildSuccessResponse(string $action, array $result): array
    {
        $data = (array) ($result['data'] ?? $result);

        if ($action === 'download') {
            $urls = array_values(array_filter(array_map(
                static fn ($item) => is_string($item) ? trim($item) : '',
                (array) ($data['urls'] ?? [])
            )));
            if ($urls === []) {
                $singleUrl = trim((string) ($data['url'] ?? ''));
                if ($singleUrl !== '') {
                    $urls[] = $singleUrl;
                }
            }

            $message = 'Minutes media is ready.';
            if ($urls !== []) {
                $message .= "\n" . implode("\n", $urls);
            }

            return [
                'status' => 'success',
                'message' => $message,
                'model' => 'feishu-minutes-download',
                'raw_data' => $data,
            ];
        }

        $minute = (array) ($data['minute'] ?? $data);
        $title = trim((string) ($minute['title'] ?? 'Minutes'));
        $url = trim((string) ($minute['url'] ?? ''));
        $token = trim((string) ($minute['token'] ?? ''));

        $message = 'Minutes info: ' . $title;
        if ($token !== '') {
            $message .= ', token=' . $token;
        }
        if ($url !== '') {
            $message .= ', url=' . $url;
        }

        return [
            'status' => 'success',
            'message' => $message,
            'model' => 'feishu-minutes-info',
            'raw_data' => $data,
        ];
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>
     */
    private function resolveMinuteTokens(array $params): array
    {
        $tokens = $this->normalizeStringList($params['minute_tokens'] ?? []);
        $minuteUrl = trim((string) ($params['minute_url'] ?? ''));
        if ($minuteUrl !== '' && preg_match('#/minutes/([A-Za-z0-9_-]+)#', $minuteUrl, $matches) === 1) {
            $tokens[] = trim((string) ($matches[1] ?? ''));
        }

        return array_values(array_unique(array_filter($tokens, static fn ($item) => $item !== '')));
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


}
