<?php

namespace App\Services;

class LarkResultNormalizerService
{
    /**
     * @param  array<string,mixed>  $workAction
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    public function normalize(array $workAction, array $result): array
    {
        $status = strtolower(trim((string) ($result['status'] ?? 'failed')));
        $normalizedStatus = match ($status) {
            'blocked', 'failed', 'clarify', 'authorize' => $status,
            'created', 'success', 'read', 'write', 'append', 'info' => 'success',
            default => $status === '' ? 'failed' : 'success',
        };

        $inputTokens = max(0, (int) (($result['_extraction_input_tokens'] ?? 0) + ($result['input_tokens'] ?? 0)));
        $outputTokens = max(0, (int) (($result['_extraction_output_tokens'] ?? 0) + ($result['output_tokens'] ?? 0)));

        return [
            'handled' => true,
            'status' => $normalizedStatus,
            'answer' => (string) ($result['message'] ?? $result['answer'] ?? ''),
            'model' => (string) ($result['model'] ?? ($workAction['executor'] ?? ('lark_cli.' . ($workAction['action_key'] ?? 'action')))),
            'task_kind' => (string) ($workAction['task_kind'] ?? 'general_task'),
            'work_action' => (string) ($workAction['action_key'] ?? ''),
            'missing_capabilities' => array_values((array) ($result['missing'] ?? $result['missing_capabilities'] ?? [])),
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'raw' => $result,
        ];
    }
}
