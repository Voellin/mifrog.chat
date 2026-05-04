<?php

namespace App\Services;

use App\Models\Run;
use Throwable;

class FeishuApprovalTaskService extends AbstractFeishuTaskService
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
                'message' => 'Please tell me whether you want to query, approve, reject, transfer, or inspect an approval.',
            ];
        }

        if (($params['needs_clarification'] ?? false) === true) {
            $message = trim((string) ($params['clarification_message'] ?? ''));

            return [
                'status' => 'clarify',
                'message' => $message !== '' ? $message : 'Please provide the approval task id or instance code that you want me to operate on.',
            ];
        }

        if (! $this->feishuCliClient->isEnabled() || ! $this->feishuCliClient->isAvailable()) {
            return [
                'status' => 'failed',
                'message' => 'Feishu CLI is not available, so approval operations cannot run right now.',
            ];
        }

        $config = $this->feishuService->readConfig();
        $action = strtolower(trim((string) ($params['action'] ?? 'query')));
        $command = $this->buildCommand($action, $params);
        if ($command === null) {
            return [
                'status' => 'clarify',
                'message' => 'Please provide the approval task id or instance code that you want me to operate on.',
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
            return $this->blockedFromThrowable($e, 'Feishu authorization is required before I can continue with approval operations.');
        }

        $code = (int) ($result['code'] ?? 0);
        if ($code !== 0) {
            $msg = trim((string) ($result['msg'] ?? 'approval_operation_failed'));

            return [
                'status' => 'failed',
                'message' => 'Approval operation failed: ' . $msg,
                'error' => $result,
            ];
        }

        return $this->buildSuccessResponse($action, $result, $params);
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>|null
     */
    private function buildCommand(string $action, array $params): ?array
    {
        return match ($action) {
            'query' => $this->buildQueryCommand($params),
            'approve' => $this->buildDecisionCommand('approve', $params),
            'reject' => $this->buildDecisionCommand('reject', $params),
            'transfer' => $this->buildTransferCommand($params),
            'get_instance' => $this->buildInstanceGetCommand($params),
            'cancel_instance' => $this->buildInstanceCancelCommand($params),
            default => null,
        };
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>
     */
    private function buildQueryCommand(array $params): array
    {
        $topic = $this->mapTopicCode(trim((string) ($params['topic'] ?? 'pending')));
        $query = json_encode([
            'topic' => $topic,
            'locale' => 'zh-CN',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"topic":"1","locale":"zh-CN"}';

        return ['approval', 'tasks', 'query', '--params', $query];
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>|null
     */
    private function buildDecisionCommand(string $action, array $params): ?array
    {
        $taskId = trim((string) ($params['task_id'] ?? ''));
        $instanceCode = trim((string) ($params['instance_code'] ?? ''));
        if ($taskId === '' || $instanceCode === '') {
            return null;
        }

        $data = [
            'task_id' => $taskId,
            'instance_code' => $instanceCode,
        ];

        $comment = trim((string) ($params['comment'] ?? ''));
        if ($comment !== '') {
            $data['comment'] = $comment;
        }

        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($payload)) {
            return null;
        }

        return ['approval', 'tasks', $action, '--data', $payload];
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>|null
     */
    private function buildTransferCommand(array $params): ?array
    {
        $taskId = trim((string) ($params['task_id'] ?? ''));
        $instanceCode = trim((string) ($params['instance_code'] ?? ''));
        $transferUserId = trim((string) ($params['transfer_user_id'] ?? ''));
        if ($taskId === '' || $instanceCode === '' || $transferUserId === '') {
            return null;
        }

        $userIdType = strtolower(trim((string) ($params['transfer_user_id_type'] ?? 'open_id')));
        if (! in_array($userIdType, ['open_id', 'union_id', 'user_id'], true)) {
            $userIdType = 'open_id';
        }

        $data = [
            'task_id' => $taskId,
            'instance_code' => $instanceCode,
            'transfer_user_id' => $transferUserId,
        ];

        $comment = trim((string) ($params['comment'] ?? ''));
        if ($comment !== '') {
            $data['comment'] = $comment;
        }

        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $query = json_encode(['user_id_type' => $userIdType], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($payload) || ! is_string($query)) {
            return null;
        }

        return ['approval', 'tasks', 'transfer', '--params', $query, '--data', $payload];
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>|null
     */
    private function buildInstanceGetCommand(array $params): ?array
    {
        $instanceCode = trim((string) ($params['instance_code'] ?? ''));
        if ($instanceCode === '') {
            return null;
        }

        $query = json_encode([
            'instance_code' => $instanceCode,
            'locale' => 'zh-CN',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($query)) {
            return null;
        }

        return ['approval', 'instances', 'get', '--params', $query];
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>|null
     */
    private function buildInstanceCancelCommand(array $params): ?array
    {
        $instanceCode = trim((string) ($params['instance_code'] ?? ''));
        if ($instanceCode === '') {
            return null;
        }

        $payload = json_encode(['instance_code' => $instanceCode], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($payload)) {
            return null;
        }

        return ['approval', 'instances', 'cancel', '--data', $payload];
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function buildSuccessResponse(string $action, array $result, array $params): array
    {
        $data = (array) ($result['data'] ?? $result);

        return match ($action) {
            'query' => $this->buildQueryResponse($data, $params),
            'approve' => [
                'status' => 'success',
                'message' => 'Approval task approved successfully.',
                'model' => 'feishu-approval-approve',
                'raw_data' => $data,
            ],
            'reject' => [
                'status' => 'success',
                'message' => 'Approval task rejected successfully.',
                'model' => 'feishu-approval-reject',
                'raw_data' => $data,
            ],
            'transfer' => [
                'status' => 'success',
                'message' => 'Approval task transferred successfully.',
                'model' => 'feishu-approval-transfer',
                'raw_data' => $data,
            ],
            'cancel_instance' => [
                'status' => 'success',
                'message' => 'Approval instance canceled successfully.',
                'model' => 'feishu-approval-cancel',
                'raw_data' => $data,
            ],
            'get_instance' => $this->buildInstanceResponse($data),
            default => [
                'status' => 'success',
                'message' => 'Approval operation completed successfully.',
                'model' => 'feishu-approval',
                'raw_data' => $data,
            ],
        };
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function buildQueryResponse(array $data, array $params): array
    {
        $tasks = array_values(array_filter((array) ($data['tasks'] ?? []), 'is_array'));
        $label = $this->topicLabel((string) ($params['topic'] ?? 'pending'));
        if ($tasks === []) {
            return [
                'status' => 'success',
                'message' => 'There are no approval tasks in the ' . $label . ' list.',
                'model' => 'feishu-approval-query',
                'raw_data' => $data,
            ];
        }

        $lines = ['Approval tasks in ' . $label . ':'];
        foreach (array_slice($tasks, 0, 5) as $index => $task) {
            $name = trim((string) ($task['definition_name'] ?? 'Approval'));
            $initiator = trim((string) ($task['initiator_name'] ?? ''));
            $taskId = trim((string) ($task['task_id'] ?? ''));
            $instanceCode = trim((string) ($task['instance_code'] ?? ''));
            $status = trim((string) ($task['instance_status'] ?? ''));

            $parts = [$name];
            if ($initiator !== '') {
                $parts[] = 'initiator=' . $initiator;
            }
            if ($taskId !== '') {
                $parts[] = 'task_id=' . $taskId;
            }
            if ($instanceCode !== '') {
                $parts[] = 'instance=' . $instanceCode;
            }
            if ($status !== '') {
                $parts[] = 'status=' . $status;
            }

            $lines[] = ($index + 1) . '. ' . implode(', ', $parts);
        }

        if (count($tasks) > 5) {
            $lines[] = 'There are more results, but I only listed the top 5.';
        }

        return [
            'status' => 'success',
            'message' => implode("\n", $lines),
            'model' => 'feishu-approval-query',
            'raw_data' => $data,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function buildInstanceResponse(array $data): array
    {
        $definitionName = trim((string) ($data['definition_name'] ?? 'Approval'));
        $status = trim((string) ($data['status'] ?? ($data['instance_status'] ?? '')));
        $instanceCode = trim((string) ($data['instance_code'] ?? ''));
        $initiator = trim((string) ($data['user_name'] ?? ($data['initiator_name'] ?? '')));

        $parts = [$definitionName];
        if ($status !== '') {
            $parts[] = 'status=' . $status;
        }
        if ($initiator !== '') {
            $parts[] = 'initiator=' . $initiator;
        }
        if ($instanceCode !== '') {
            $parts[] = 'instance=' . $instanceCode;
        }

        return [
            'status' => 'success',
            'message' => 'Approval instance details: ' . implode(', ', $parts),
            'model' => 'feishu-approval-instance',
            'raw_data' => $data,
        ];
    }

    private function mapTopicCode(string $topic): string
    {
        return match (strtolower(trim($topic))) {
            'processed', 'done', 'approved', 'handled', '2' => '2',
            'initiated', 'created', 'submitted', '3' => '3',
            'cc_unread', 'cc-unread', '17' => '17',
            'cc_read', 'cc-read', '18' => '18',
            default => '1',
        };
    }

    private function topicLabel(string $topic): string
    {
        return match ($this->mapTopicCode($topic)) {
            '2' => 'processed',
            '3' => 'initiated',
            '17' => 'cc unread',
            '18' => 'cc read',
            default => 'pending',
        };
    }


}
