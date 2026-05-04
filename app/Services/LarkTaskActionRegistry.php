<?php

namespace App\Services;

use App\Models\Run;
use Closure;

/**
 * Central action_key -> task service method dispatch registry.
 *
 * Originally introduced when both LarkCliExecutorService (now removed)
 * and ToolCallExecutorService maintained their own 16+ case match
 * expressions and kept drifting. After the LarkCli path was decommissioned,
 * this registry remains the single source of truth for ToolCallExecutorService
 * to look up action_key -> task service method, while the executor only keeps
 * skill.* and request_authorization branching on top.
 *
 * Introduced 2026-04-21 as part of P1.2 refactor.
 *
 * Signature contract for handlers:
 *   Closure(Run $run, array $params, ?array $rawMessages, ?callable $progressCallback): ?array
 * Callers not needing $rawMessages / $progressCallback can pass null.
 */
class LarkTaskActionRegistry
{
    /** @var array<string, Closure> */
    private readonly array $handlers;

    public function __construct(
        CalendarTaskService $calendar,
        ChatTaskService $chat,
        FeishuTaskManageService $taskManage,
        FeishuDocsTaskService $docs,
        FeishuSheetsTaskService $sheets,
        FeishuContactTaskService $contact,
        FeishuApprovalTaskService $approval,
        FeishuBaseTaskService $base,
        FeishuMeetingTaskService $meeting,
        FeishuMinutesTaskService $minutes,
        FeishuMailTaskService $mail,
        FeishuWikiTaskService $wiki,
        FeishuDriveTaskService $drive,
    ) {
        $this->handlers = [
            'calendar.create' => static fn (Run $run, array $params, ?array $raw, ?callable $cb): array
                => $calendar->createEvent($run, $params),

            'calendar.attendees.add' => static fn (Run $run, array $params, ?array $raw, ?callable $cb): array
                => $calendar->addAttendeesToEvent($run, $params, $raw ?? []),

            'calendar.agenda' => static fn (Run $run, array $params, ?array $raw, ?callable $cb): array
                => $calendar->readAgenda($run, $params),

            'tasks.create' => static fn (Run $run, array $params, ?array $raw, ?callable $cb): array
                => $taskManage->createTask($run, $params),

            'docs.create' => static fn (Run $run, array $params, ?array $raw, ?callable $cb): array
                => $docs->execute($run, array_merge($params, ['action' => 'create']), $raw ?? [], $cb),

            'docs.read' => static fn (Run $run, array $params, ?array $raw, ?callable $cb): array
                => $docs->execute($run, array_merge($params, ['action' => 'read']), $raw ?? [], $cb),

            'sheets.create' => static fn (Run $run, array $params, ?array $raw, ?callable $cb): array
                => $sheets->execute($run, array_merge($params, ['action' => 'create'])),

            'sheets.read' => static fn (Run $run, array $params, ?array $raw, ?callable $cb): array
                => $sheets->execute($run, array_merge($params, ['action' => 'read'])),

            'sheets.write' => static fn (Run $run, array $params, ?array $raw, ?callable $cb): array
                => $sheets->execute($run, array_merge($params, ['action' => 'write'])),

            'sheets.append' => static fn (Run $run, array $params, ?array $raw, ?callable $cb): array
                => $sheets->execute($run, array_merge($params, ['action' => 'append'])),

            'contact.lookup' => static fn (Run $run, array $params, ?array $raw, ?callable $cb): array
                => $contact->execute($run, $params),

            'approval.manage' => static fn (Run $run, array $params, ?array $raw, ?callable $cb): array
                => $approval->execute($run, $params),

            'base.manage' => static fn (Run $run, array $params, ?array $raw, ?callable $cb): array
                => $base->execute($run, $params),

            'meeting.manage' => static fn (Run $run, array $params, ?array $raw, ?callable $cb): array
                => $meeting->execute($run, $params),

            'minutes.manage' => static fn (Run $run, array $params, ?array $raw, ?callable $cb): array
                => $minutes->execute($run, $params),

            'mail.manage' => static fn (Run $run, array $params, ?array $raw, ?callable $cb): array
                => $mail->execute($run, $params),

            'wiki.manage' => static fn (Run $run, array $params, ?array $raw, ?callable $cb): array
                => $wiki->execute($run, $params),

            'drive.manage' => static fn (Run $run, array $params, ?array $raw, ?callable $cb): array
                => $drive->execute($run, $params),

            'chat.history_read' => static fn (Run $run, array $params, ?array $raw, ?callable $cb): array
                => $chat->readHistory($run, $params),
        ];
    }

    public function has(string $actionKey): bool
    {
        return isset($this->handlers[$actionKey]);
    }

    /**
     * @return array<int, string>
     */
    public function actionKeys(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * Dispatch an action. Returns null if the action_key is unknown or
     * the underlying task service returned a non-array result.
     *
     * @param  array<string, mixed>  $params
     * @param  array<int, array<string, mixed>>|null  $rawMessages
     * @return array<string, mixed>|null
     */
    public function dispatch(
        string $actionKey,
        Run $run,
        array $params,
        ?array $rawMessages = null,
        ?callable $progressCallback = null,
    ): ?array {
        $handler = $this->handlers[$actionKey] ?? null;
        if ($handler === null) {
            return null;
        }

        $result = $handler($run, $params, $rawMessages, $progressCallback);

        return is_array($result) ? $result : null;
    }
}
