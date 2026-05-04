<?php

namespace App\Services;

use App\Models\Run;
use Throwable;

class FeishuContactTaskService extends AbstractFeishuTaskService
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
                'message' => 'Please tell me which contact you want to look up.',
            ];
        }

        if (($params['needs_clarification'] ?? false) === true) {
            $message = trim((string) ($params['clarification_message'] ?? ''));

            return [
                'status' => 'clarify',
                'message' => $message !== '' ? $message : 'Please tell me which contact you want to look up.',
            ];
        }

        if (! $this->feishuCliClient->isEnabled() || ! $this->feishuCliClient->isAvailable()) {
            return [
                'status' => 'failed',
                'message' => 'Feishu CLI is not available, so contact lookup cannot run right now.',
            ];
        }

        $config = $this->feishuService->readConfig();
        $action = strtolower(trim((string) ($params['action'] ?? 'search_user')));
        $command = $this->buildCommand($action, $params);
        if ($command === null) {
            return [
                'status' => 'clarify',
                'message' => 'Please provide a contact name, email, phone number, or user id.',
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
            return $this->blockedFromThrowable($e, 'Feishu authorization is required before I can look up contacts.');
        }

        $code = (int) ($result['code'] ?? 0);
        if ($code !== 0) {
            $msg = trim((string) ($result['msg'] ?? 'contact_lookup_failed'));

            return [
                'status' => 'failed',
                'message' => 'Contact lookup failed: ' . $msg,
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
            'get_self' => ['contact', '+get-user'],
            'get_user' => $this->buildGetUserCommand($params),
            'search_user' => $this->buildSearchCommand($params),
            default => null,
        };
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>|null
     */
    private function buildGetUserCommand(array $params): ?array
    {
        $userId = trim((string) ($params['user_id'] ?? ''));
        if ($userId === '') {
            return null;
        }

        $userIdType = strtolower(trim((string) ($params['user_id_type'] ?? 'open_id')));
        if (! in_array($userIdType, ['open_id', 'union_id', 'user_id'], true)) {
            $userIdType = 'open_id';
        }

        return ['contact', '+get-user', '--user-id', $userId, '--user-id-type', $userIdType];
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>|null
     */
    private function buildSearchCommand(array $params): ?array
    {
        $query = trim((string) ($params['query'] ?? ''));
        if ($query === '') {
            return null;
        }

        return ['contact', '+search-user', '--query', $query];
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function buildSuccessResponse(string $action, array $result): array
    {
        $data = (array) ($result['data'] ?? $result);

        if ($action === 'search_user') {
            $users = array_values(array_filter((array) ($data['users'] ?? []), 'is_array'));
            if ($users === []) {
                return [
                    'status' => 'success',
                    'message' => 'No matching contact was found in Feishu.',
                    'model' => 'feishu-contact-search',
                    'raw_data' => $data,
                ];
            }

            $lines = ['I found these contacts in Feishu:'];
            foreach (array_slice($users, 0, 5) as $index => $user) {
                $lines[] = ($index + 1) . '. ' . $this->formatUserLine((array) $user);
            }

            if (count($users) > 5) {
                $lines[] = 'There are more matches, but I only listed the top 5.';
            }

            return [
                'status' => 'success',
                'message' => implode("\n", $lines),
                'model' => 'feishu-contact-search',
                'raw_data' => $data,
            ];
        }

        $user = (array) ($data['user'] ?? []);
        if ($user === []) {
            return [
                'status' => 'failed',
                'message' => 'Feishu returned an empty contact profile.',
                'raw_data' => $data,
            ];
        }

        return [
            'status' => 'success',
            'message' => 'Contact profile: ' . $this->formatUserLine($user),
            'model' => 'feishu-contact-profile',
            'raw_data' => $data,
        ];
    }

    /**
     * @param  array<string,mixed>  $user
     */
    private function formatUserLine(array $user): string
    {
        $parts = [];

        $name = trim((string) ($user['name'] ?? ($user['en_name'] ?? '')));
        if ($name !== '') {
            $parts[] = $name;
        }

        $email = trim((string) ($user['email'] ?? ($user['enterprise_email'] ?? '')));
        if ($email !== '') {
            $parts[] = 'email=' . $email;
        }

        $mobile = trim((string) ($user['mobile'] ?? ''));
        if ($mobile !== '') {
            $parts[] = 'mobile=' . $mobile;
        }

        $userId = trim((string) ($user['user_id'] ?? ''));
        if ($userId !== '') {
            $parts[] = 'user_id=' . $userId;
        }

        $openId = trim((string) ($user['open_id'] ?? ''));
        if ($openId !== '') {
            $parts[] = 'open_id=' . $openId;
        }

        return $parts !== [] ? implode(', ', $parts) : 'empty contact record';
    }


}
