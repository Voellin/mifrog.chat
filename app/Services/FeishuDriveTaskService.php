<?php

namespace App\Services;

use App\Models\Run;
use Throwable;

class FeishuDriveTaskService extends AbstractFeishuTaskService
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
                'message' => 'Please tell me whether you want to list drive files, move a file, or add a comment to a document.',
            ];
        }

        if (($params['needs_clarification'] ?? false) === true) {
            return [
                'status' => 'clarify',
                'message' => trim((string) ($params['clarification_message'] ?? '')) ?: 'Please tell me which document or folder you want me to use.',
            ];
        }

        if (! $this->feishuCliClient->isEnabled() || ! $this->feishuCliClient->isAvailable()) {
            return [
                'status' => 'failed',
                'message' => 'Feishu CLI is not available, so drive operations cannot run right now.',
            ];
        }

        $config = $this->feishuService->readConfig();
        $action = strtolower(trim((string) ($params['action'] ?? 'list_root')));
        $command = $this->buildCommand($action, $params);
        if ($command === null) {
            return [
                'status' => 'clarify',
                'message' => 'Please tell me which document or folder you want me to use.',
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
            return $this->blockedFromThrowable($e, 'Feishu authorization is required before I can access Drive files.');
        }

        $code = (int) ($result['code'] ?? 0);
        if ($code !== 0) {
            return [
                'status' => 'failed',
                'message' => 'Drive operation failed: ' . trim((string) ($result['msg'] ?? 'drive_operation_failed')),
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
            'move' => $this->buildMoveCommand($params),
            'add_comment' => $this->buildCommentCommand($params),
            default => $this->buildListCommand($params),
        };
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>
     */
    private function buildListCommand(array $params): array
    {
        $query = ['page_size' => 10];
        $folderToken = trim((string) ($params['folder_token'] ?? ''));
        if ($folderToken !== '') {
            $query['folder_token'] = $folderToken;
        }

        $payload = json_encode($query, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"page_size":10}';

        return ['drive', 'files', 'list', '--params', $payload];
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>|null
     */
    private function buildMoveCommand(array $params): ?array
    {
        $fileToken = trim((string) ($params['file_token'] ?? ''));
        $folderToken = trim((string) ($params['folder_token'] ?? ''));
        if ($fileToken === '' || $folderToken === '') {
            return null;
        }

        $command = ['drive', '+move', '--file-token', $fileToken, '--folder-token', $folderToken];
        $fileType = trim((string) ($params['file_type'] ?? ''));
        if ($fileType !== '') {
            $command[] = '--type';
            $command[] = $fileType;
        }

        return $command;
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>|null
     */
    private function buildCommentCommand(array $params): ?array
    {
        $doc = trim((string) ($params['doc'] ?? ''));
        $content = trim((string) ($params['content'] ?? ''));
        if ($doc === '' || $content === '') {
            return null;
        }

        $elements = json_encode([['type' => 'text', 'text' => $content]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($elements)) {
            return null;
        }

        $command = ['drive', '+add-comment', '--doc', $doc, '--content', $elements];
        $selection = trim((string) ($params['selection_with_ellipsis'] ?? ''));
        if ($selection !== '') {
            $command[] = '--selection-with-ellipsis';
            $command[] = $selection;
        }
        if ((bool) ($params['full_comment'] ?? true) === true) {
            $command[] = '--full-comment';
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
            'move' => $this->buildMoveResponse($data),
            'add_comment' => $this->buildCommentResponse($data),
            default => $this->buildListResponse($data),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function buildListResponse(array $data): array
    {
        $files = array_values(array_filter((array) ($data['files'] ?? []), 'is_array'));
        if ($files === []) {
            return [
                'status' => 'success',
                'message' => 'I did not find any files in that Drive location.',
                'model' => 'feishu-drive-list',
                'raw_data' => $data,
            ];
        }

        $lines = ['Drive files:'];
        foreach (array_slice($files, 0, 10) as $index => $file) {
            $name = trim((string) ($file['name'] ?? 'Untitled file'));
            $token = trim((string) ($file['token'] ?? ''));
            $type = trim((string) ($file['type'] ?? ''));
            $parts = [$name];
            if ($type !== '') {
                $parts[] = 'type=' . $type;
            }
            if ($token !== '') {
                $parts[] = 'token=' . $token;
            }
            $lines[] = ($index + 1) . '. ' . implode(', ', $parts);
        }

        return [
            'status' => 'success',
            'message' => implode("\n", $lines),
            'model' => 'feishu-drive-list',
            'raw_data' => $data,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function buildMoveResponse(array $data): array
    {
        $message = 'Drive file moved successfully.';
        $url = trim((string) (($data['file']['url'] ?? null) ?: ($data['url'] ?? '')));
        if ($url !== '') {
            $message .= ' ' . $url;
        }

        return [
            'status' => 'success',
            'message' => $message,
            'model' => 'feishu-drive-move',
            'raw_data' => $data,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function buildCommentResponse(array $data): array
    {
        $commentId = trim((string) ($data['comment_id'] ?? ''));
        $message = 'Drive comment added successfully.';
        if ($commentId !== '') {
            $message .= ' comment_id=' . $commentId;
        }

        return [
            'status' => 'success',
            'message' => $message,
            'model' => 'feishu-drive-comment',
            'raw_data' => $data,
        ];
    }


}
