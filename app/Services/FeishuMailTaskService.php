<?php

namespace App\Services;

use App\Models\Run;
use Throwable;

class FeishuMailTaskService extends AbstractFeishuTaskService
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
                'message' => 'Please tell me whether you want to search mail, read a message, read a thread, or draft an email.',
            ];
        }

        if (($params['needs_clarification'] ?? false) === true) {
            return [
                'status' => 'clarify',
                'message' => trim((string) ($params['clarification_message'] ?? '')) ?: 'Please tell me whether you want to search mail, read a message, read a thread, or draft an email.',
            ];
        }

        if (! $this->feishuCliClient->isEnabled() || ! $this->feishuCliClient->isAvailable()) {
            return [
                'status' => 'failed',
                'message' => 'Feishu CLI is not available, so mail operations cannot run right now.',
            ];
        }

        $config = $this->feishuService->readConfig();
        $action = strtolower(trim((string) ($params['action'] ?? 'search')));
        $command = $this->buildCommand($action, $params);
        if ($command === null) {
            return [
                'status' => 'clarify',
                'message' => 'Please provide a mail query, message id, thread id, or the recipients and content for the email draft.',
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
            return $this->blockedFromThrowable($e, 'Feishu authorization is required before I can access mail.');
        }

        $code = (int) ($result['code'] ?? 0);
        if ($code !== 0) {
            return [
                'status' => 'failed',
                'message' => 'Mail operation failed: ' . trim((string) ($result['msg'] ?? 'mail_operation_failed')),
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
            'read' => $this->buildReadCommand($params),
            'thread' => $this->buildThreadCommand($params),
            'compose' => $this->buildComposeCommand($params),
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
        if ($query === '') {
            return null;
        }

        return ['mail', '+triage', '--format', 'json', '--query', $query, '--max', '10'];
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>|null
     */
    private function buildReadCommand(array $params): ?array
    {
        $messageId = trim((string) ($params['message_id'] ?? ''));
        if ($messageId === '') {
            return null;
        }

        return ['mail', '+message', '--format', 'json', '--message-id', $messageId, '--mailbox', trim((string) ($params['mailbox'] ?? 'me')) ?: 'me'];
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>|null
     */
    private function buildThreadCommand(array $params): ?array
    {
        $threadId = trim((string) ($params['thread_id'] ?? ''));
        if ($threadId === '') {
            return null;
        }

        return ['mail', '+thread', '--format', 'json', '--thread-id', $threadId, '--mailbox', trim((string) ($params['mailbox'] ?? 'me')) ?: 'me'];
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>|null
     */
    private function buildComposeCommand(array $params): ?array
    {
        $subject = trim((string) ($params['subject'] ?? ''));
        $body = trim((string) ($params['body'] ?? ''));
        $to = $this->normalizeAddressList($params['to'] ?? []);
        $cc = $this->normalizeAddressList($params['cc'] ?? []);
        $bcc = $this->normalizeAddressList($params['bcc'] ?? []);

        if ($subject === '' || $body === '' || ($to === [] && $cc === [] && $bcc === [])) {
            return null;
        }

        $command = [
            'mail', '+send',
            '--subject', $subject,
            '--body', $body,
        ];

        $mailbox = trim((string) ($params['mailbox'] ?? 'me'));
        if ($mailbox !== '' && strtolower($mailbox) !== 'me') {
            $command[] = '--from';
            $command[] = $mailbox;
        }

        if ($to !== []) {
            $command[] = '--to';
            $command[] = implode(',', $to);
        }
        if ($cc !== []) {
            $command[] = '--cc';
            $command[] = implode(',', $cc);
        }
        if ($bcc !== []) {
            $command[] = '--bcc';
            $command[] = implode(',', $bcc);
        }
        if ((bool) ($params['plain_text'] ?? false) === true) {
            $command[] = '--plain-text';
        }
        if ((bool) ($params['confirm_send'] ?? false) === true) {
            $command[] = '--confirm-send';
        }

        return $command;
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
            'read' => $this->buildReadResponse($data),
            'thread' => $this->buildThreadResponse($data),
            'compose' => $this->buildComposeResponse($data, $params),
            default => $this->buildSearchResponse($data),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function buildSearchResponse(array $data): array
    {
        $messages = array_values(array_filter(
            (array) ($data['messages'] ?? ($data['items'] ?? [])),
            'is_array'
        ));
        if ($messages === []) {
            return [
                'status' => 'success',
                'message' => 'I did not find matching emails.',
                'model' => 'feishu-mail-search',
                'raw_data' => $data,
            ];
        }

        $lines = ['I found these emails:'];
        foreach (array_slice($messages, 0, 5) as $index => $message) {
            $subject = trim((string) ($message['subject'] ?? 'Untitled email'));
            $messageId = trim((string) ($message['message_id'] ?? ''));
            $from = trim((string) (($message['from']['mail_address'] ?? null) ?: ($message['head_from']['mail_address'] ?? '')));
            $parts = [$subject];
            if ($from !== '') {
                $parts[] = 'from=' . $from;
            }
            if ($messageId !== '') {
                $parts[] = 'message_id=' . $messageId;
            }
            $lines[] = ($index + 1) . '. ' . implode(', ', $parts);
        }

        return [
            'status' => 'success',
            'message' => implode("\n", $lines),
            'model' => 'feishu-mail-search',
            'raw_data' => $data,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function buildReadResponse(array $data): array
    {
        $message = (array) ($data['message'] ?? $data);
        $subject = trim((string) ($message['subject'] ?? 'Untitled email'));
        $from = trim((string) (($message['head_from']['mail_address'] ?? null) ?: ''));
        $body = trim((string) ($message['body_plain_text'] ?? ($message['body_preview'] ?? '')));

        $parts = ['Email: ' . $subject];
        if ($from !== '') {
            $parts[] = 'from=' . $from;
        }
        if ($body !== '') {
            $parts[] = "\nPreview: " . $this->truncate($body, 800);
        }

        return [
            'status' => 'success',
            'message' => implode(', ', $parts),
            'model' => 'feishu-mail-read',
            'raw_data' => $data,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function buildThreadResponse(array $data): array
    {
        $messages = array_values(array_filter(
            (array) ($data['messages'] ?? ($data['items'] ?? [])),
            'is_array'
        ));
        $count = count($messages);
        $message = 'Mail thread loaded with ' . $count . ' message(s).';
        if ($count > 0) {
            $first = (array) $messages[0];
            $subject = trim((string) ($first['subject'] ?? ''));
            if ($subject !== '') {
                $message .= ' Subject: ' . $subject;
            }
        }

        return [
            'status' => 'success',
            'message' => $message,
            'model' => 'feishu-mail-thread',
            'raw_data' => $data,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function buildComposeResponse(array $data, array $params): array
    {
        $sent = (bool) ($params['confirm_send'] ?? false);
        $subject = trim((string) ($params['subject'] ?? ''));
        $recipients = $this->normalizeAddressList($params['to'] ?? []);

        $message = $sent ? 'Email sent successfully.' : 'Email draft created successfully.';
        if ($subject !== '') {
            $message .= ' Subject: ' . $subject . '.';
        }
        if ($recipients !== []) {
            $message .= ' To: ' . implode(', ', $recipients) . '.';
        }

        return [
            'status' => 'success',
            'message' => $message,
            'model' => $sent ? 'feishu-mail-send' : 'feishu-mail-draft',
            'raw_data' => $data,
        ];
    }

    /**
     * @param  mixed  $value
     * @return array<int,string>
     */
    private function normalizeAddressList(mixed $value): array
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
