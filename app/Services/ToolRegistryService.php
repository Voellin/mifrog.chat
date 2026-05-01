<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class ToolRegistryService
{
    private const DEFAULT_TIMEZONE = 'Asia/Shanghai';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTools(): array
    {
        return array_map(
            static fn (array $definition) => [
                'type' => 'function',
                'function' => $definition,
            ],
            $this->definitions()
        );
    }

    /**
     * @return array<string, string>
     */
    public function getWorkActionMeta(string $actionKey): array
    {
        $map = [
            'calendar.create' => ['executor' => 'lark_cli.calendar', 'task_kind' => 'calendar_create', 'planner_profile' => 'calendar'],
            'calendar.attendees.add' => ['executor' => 'lark_cli.calendar', 'task_kind' => 'calendar_attendees_add', 'planner_profile' => 'calendar_attendees'],
            'calendar.agenda' => ['executor' => 'lark_cli.calendar', 'task_kind' => 'feishu_calendar_agenda', 'planner_profile' => 'calendar'],
            'tasks.create' => ['executor' => 'lark_cli.tasks', 'task_kind' => 'task_manage', 'planner_profile' => 'task'],
            'docs.create' => ['executor' => 'lark_cli.docs', 'task_kind' => 'feishu_doc_create', 'planner_profile' => 'docs'],
            'docs.read' => ['executor' => 'lark_cli.docs', 'task_kind' => 'feishu_doc_read', 'planner_profile' => 'docs'],
            'sheets.create' => ['executor' => 'lark_cli.sheets', 'task_kind' => 'feishu_sheet_create', 'planner_profile' => 'sheets'],
            'sheets.read' => ['executor' => 'lark_cli.sheets', 'task_kind' => 'feishu_sheet_read', 'planner_profile' => 'sheets'],
            'sheets.write' => ['executor' => 'lark_cli.sheets', 'task_kind' => 'feishu_sheet_write', 'planner_profile' => 'sheets'],
            'sheets.append' => ['executor' => 'lark_cli.sheets', 'task_kind' => 'feishu_sheet_append', 'planner_profile' => 'sheets'],
            'contact.lookup' => ['executor' => 'lark_cli.contact', 'task_kind' => 'feishu_contact_lookup', 'planner_profile' => 'contact'],
            'approval.manage' => ['executor' => 'lark_cli.approval', 'task_kind' => 'feishu_approval_manage', 'planner_profile' => 'approval'],
            'base.manage' => ['executor' => 'lark_cli.base', 'task_kind' => 'feishu_base_manage', 'planner_profile' => 'base'],
            'meeting.manage' => ['executor' => 'lark_cli.meeting', 'task_kind' => 'feishu_meeting_manage', 'planner_profile' => 'meeting'],
            'minutes.manage' => ['executor' => 'lark_cli.minutes', 'task_kind' => 'feishu_minutes_manage', 'planner_profile' => 'minutes'],
            'mail.manage' => ['executor' => 'lark_cli.mail', 'task_kind' => 'feishu_mail_manage', 'planner_profile' => 'mail'],
            'wiki.manage' => ['executor' => 'lark_cli.wiki', 'task_kind' => 'feishu_wiki_manage', 'planner_profile' => 'wiki'],
            'drive.manage' => ['executor' => 'lark_cli.drive', 'task_kind' => 'feishu_drive_manage', 'planner_profile' => 'drive'],
            'chat.history_read' => ['executor' => 'lark_cli.chat', 'task_kind' => 'feishu_chat_history_read', 'planner_profile' => 'chat'],
            'request_authorization' => ['executor' => 'system.auth', 'task_kind' => 'request_authorization', 'planner_profile' => 'auth'],
            'skill.load' => ['executor' => 'internal.skill', 'task_kind' => 'skill_load', 'planner_profile' => 'skill'],
            'skill.execute_sandbox' => ['executor' => 'internal.skill', 'task_kind' => 'skill_execute_sandbox', 'planner_profile' => 'skill'],
            'skill.execute_api' => ['executor' => 'internal.skill', 'task_kind' => 'skill_execute_api', 'planner_profile' => 'skill'],
        ];

        $meta = $map[$actionKey] ?? null;
        if ($meta === null) {
            return [];
        }

        $meta['action_key'] = $actionKey;

        return $meta;
    }

    public function getTimeContext(): string
    {
        $now = CarbonImmutable::now(self::DEFAULT_TIMEZONE);
        $weekday = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$now->dayOfWeek];

        return sprintf(
            'Current time: %s (%s), timezone: %s',
            $now->format('Y-m-d H:i:s'),
            $weekday,
            self::DEFAULT_TIMEZONE
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function definitions(): array
    {
        return [
            [
                'name' => 'calendar_create',
                'description' => 'Create a Feishu calendar event or meeting.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'summary' => ['type' => 'string', 'description' => 'Event title.'],
                        'start_time' => ['type' => 'string', 'description' => 'ISO 8601 start time.'],
                        'end_time' => ['type' => 'string', 'description' => 'ISO 8601 end time.'],
                        'location' => ['type' => 'string', 'description' => 'Event location.'],
                        'description' => ['type' => 'string', 'description' => 'Event description or notes.'],
                    ],
                    'required' => ['summary', 'start_time'],
                ],
            ],
            [
                'name' => 'calendar_attendees_add',
                'description' => 'Add attendees to an existing Feishu calendar event.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'string', 'description' => 'Event identifier if known.'],
                        'calendar_id' => ['type' => 'string', 'description' => 'Calendar identifier if known.'],
                        'event_url' => ['type' => 'string', 'description' => 'Event detail URL when the user refers to the just-created calendar event.'],
                        'event_summary' => ['type' => 'string', 'description' => 'Event title for disambiguation.'],
                        'attendee_names' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Attendee names to add.'],
                        'attendee_user_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Feishu user ids to add when already known.'],
                        'user_id_type' => ['type' => 'string', 'enum' => ['user_id', 'open_id', 'union_id'], 'description' => 'Identifier type for attendee_user_ids.'],
                    ],
                    'required' => ['attendee_names'],
                ],
            ],
            [
                'name' => 'calendar_agenda',
                'description' => 'Read Feishu calendar events within a time range, such as "What meetings do I have tomorrow?"',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'start_time' => ['type' => 'string', 'description' => 'ISO 8601 start time for the agenda window.'],
                        'end_time' => ['type' => 'string', 'description' => 'ISO 8601 end time for the agenda window.'],
                        'keyword' => ['type' => 'string', 'description' => 'Optional keyword to narrow the agenda results.'],
                        'limit' => ['type' => 'integer', 'description' => 'Maximum number of events to summarize. Default 10.'],
                    ],
                ],
            ],
            [
                'name' => 'tasks_create',
                'description' => 'Create a Feishu task or to-do item.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'summary' => ['type' => 'string', 'description' => 'Task title.'],
                        'description' => ['type' => 'string', 'description' => 'Task description.'],
                        'due_time' => ['type' => 'string', 'description' => 'ISO 8601 due time.'],
                        'due_is_all_day' => ['type' => 'boolean', 'description' => 'Whether the due time is all day.'],
                    ],
                    'required' => ['summary'],
                ],
            ],
            [
                'name' => 'docs_create',
                'description' => 'Create a Feishu document from a prompt.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => 'Document title.'],
                        'content_prompt' => ['type' => 'string', 'description' => 'The content to write or the drafting prompt.'],
                    ],
                    'required' => ['title', 'content_prompt'],
                ],
            ],
            [
                'name' => 'docs_read',
                'description' => 'Read a Feishu document by token or URL.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'doc_token' => ['type' => 'string', 'description' => 'Document token.'],
                        'doc_url' => ['type' => 'string', 'description' => 'Document URL.'],
                    ],
                ],
            ],
            [
                'name' => 'sheets_create',
                'description' => 'Create a Feishu spreadsheet.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => 'Spreadsheet title.'],
                        'headers' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Header row.'],
                        'data' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'string']], 'description' => 'Rows to write.'],
                    ],
                    'required' => ['title'],
                ],
            ],
            [
                'name' => 'sheets_read',
                'description' => 'Read a Feishu spreadsheet range.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'spreadsheet_token' => ['type' => 'string', 'description' => 'Spreadsheet token.'],
                        'spreadsheet_url' => ['type' => 'string', 'description' => 'Spreadsheet URL.'],
                        'range' => ['type' => 'string', 'description' => 'A1 range notation, such as Sheet1!A1:D10.'],
                        'sheet_id' => ['type' => 'string', 'description' => 'Sheet identifier.'],
                    ],
                ],
            ],
            [
                'name' => 'sheets_write',
                'description' => 'Write data into a Feishu spreadsheet.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'spreadsheet_token' => ['type' => 'string', 'description' => 'Spreadsheet token.'],
                        'spreadsheet_url' => ['type' => 'string', 'description' => 'Spreadsheet URL.'],
                        'range' => ['type' => 'string', 'description' => 'A1 range notation to write.'],
                        'sheet_id' => ['type' => 'string', 'description' => 'Sheet identifier.'],
                        'headers' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional header row.'],
                        'data' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'string']], 'description' => 'Rows to write.'],
                    ],
                ],
            ],
            [
                'name' => 'sheets_append',
                'description' => 'Append one or more rows to the end of a Feishu spreadsheet, especially when the user wants to add a new row without specifying an exact cell range.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'spreadsheet_token' => ['type' => 'string', 'description' => 'Spreadsheet token.'],
                        'spreadsheet_url' => ['type' => 'string', 'description' => 'Spreadsheet URL.'],
                        'range' => ['type' => 'string', 'description' => 'Optional A1 range anchor, such as A:D or Sheet1!A:D.'],
                        'sheet_id' => ['type' => 'string', 'description' => 'Sheet identifier.'],
                        'data' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'string']], 'description' => 'Rows to append.'],
                    ],
                    'required' => ['data'],
                ],
            ],
            [
                'name' => 'contact_lookup',
                'description' => 'Look up Feishu contacts or profile details.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => ['type' => 'string', 'enum' => ['get_self', 'get_user', 'search_user'], 'description' => 'Lookup mode.'],
                        'query' => ['type' => 'string', 'description' => 'Search keyword such as name, email, phone, or department.'],
                        'user_id' => ['type' => 'string', 'description' => 'Exact Feishu user id.'],
                        'user_id_type' => ['type' => 'string', 'enum' => ['open_id', 'union_id', 'user_id'], 'description' => 'Identifier type for user_id.'],
                    ],
                    'required' => ['action'],
                ],
            ],
            [
                'name' => 'approval_manage',
                'description' => 'Query or operate on Feishu approvals.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => ['type' => 'string', 'enum' => ['query', 'approve', 'reject', 'transfer', 'get_instance', 'cancel_instance'], 'description' => 'Approval operation.'],
                        'topic' => ['type' => 'string', 'enum' => ['pending', 'processed', 'initiated', 'cc_unread', 'cc_read'], 'description' => 'Approval list topic.'],
                        'task_id' => ['type' => 'string', 'description' => 'Approval task id.'],
                        'instance_code' => ['type' => 'string', 'description' => 'Approval instance code.'],
                        'comment' => ['type' => 'string', 'description' => 'Approval comment.'],
                        'transfer_user_id' => ['type' => 'string', 'description' => 'Transfer target user id.'],
                        'transfer_user_id_type' => ['type' => 'string', 'enum' => ['open_id', 'union_id', 'user_id'], 'description' => 'Identifier type for transfer_user_id.'],
                    ],
                    'required' => ['action'],
                ],
            ],
            [
                'name' => 'base_manage',
                'description' => 'Create or query a Feishu Base app, tables, or records.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => ['type' => 'string', 'enum' => ['create_base', 'create_table', 'list_tables', 'list_records', 'query_data', 'upsert_record'], 'description' => 'Base operation.'],
                        'base_name' => ['type' => 'string', 'description' => 'Base name.'],
                        'base_token' => ['type' => 'string', 'description' => 'Base token.'],
                        'base_url' => ['type' => 'string', 'description' => 'Base URL.'],
                        'table_id' => ['type' => 'string', 'description' => 'Table id.'],
                        'table_name' => ['type' => 'string', 'description' => 'Table name.'],
                        'fields' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Table field definitions.'],
                        'record' => ['type' => 'object', 'description' => 'Record payload.'],
                        'record_id' => ['type' => 'string', 'description' => 'Record id.'],
                        'limit' => ['type' => 'integer', 'description' => 'Query limit.'],
                    ],
                    'required' => ['action'],
                ],
            ],
            [
                'name' => 'meeting_manage',
                'description' => 'Search Feishu meetings or meeting notes.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => ['type' => 'string', 'enum' => ['search', 'notes'], 'description' => 'Meeting operation.'],
                        'query' => ['type' => 'string', 'description' => 'Meeting search query.'],
                        'meeting_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Meeting ids.'],
                        'minute_tokens' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Minute tokens.'],
                        'calendar_event_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Related calendar event ids.'],
                        'start' => ['type' => 'string', 'description' => 'Search start time.'],
                        'end' => ['type' => 'string', 'description' => 'Search end time.'],
                    ],
                    'required' => ['action'],
                ],
            ],
            [
                'name' => 'minutes_manage',
                'description' => 'Inspect or download Feishu minutes.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => ['type' => 'string', 'enum' => ['info', 'download'], 'description' => 'Minutes operation.'],
                        'minute_tokens' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Minute tokens.'],
                        'minute_url' => ['type' => 'string', 'description' => 'Minute URL.'],
                        'url_only' => ['type' => 'boolean', 'description' => 'Whether only a link is needed.'],
                        'output_name' => ['type' => 'string', 'description' => 'Optional download file name.'],
                    ],
                    'required' => ['action'],
                ],
            ],
            [
                'name' => 'mail_manage',
                'description' => 'Search, read, or compose Feishu mail.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => ['type' => 'string', 'enum' => ['search', 'read', 'thread', 'compose'], 'description' => 'Mail operation.'],
                        'query' => ['type' => 'string', 'description' => 'Search query.'],
                        'message_id' => ['type' => 'string', 'description' => 'Message id.'],
                        'thread_id' => ['type' => 'string', 'description' => 'Thread id.'],
                        'to' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'To recipients.'],
                        'cc' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Cc recipients.'],
                        'subject' => ['type' => 'string', 'description' => 'Mail subject.'],
                        'body' => ['type' => 'string', 'description' => 'Mail body.'],
                        'confirm_send' => ['type' => 'boolean', 'description' => 'Whether the send is confirmed.'],
                    ],
                    'required' => ['action'],
                ],
            ],
            [
                'name' => 'wiki_manage',
                'description' => 'Browse or create Feishu wiki nodes, or resolve a wiki URL/node_token to its underlying obj_type and obj_token (use action=resolve_url for any feishu.cn/wiki/... link before reading content).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => ['type' => 'string', 'enum' => ['list_spaces', 'list_nodes', 'create_node', 'resolve_url'], 'description' => 'Wiki operation. Use resolve_url to translate a wiki link or node_token into obj_type+obj_token, then dispatch to docs_read / sheets_read / base_manage / drive_manage based on obj_type.'],
                        'space_id' => ['type' => 'string', 'description' => 'Space id (for list_nodes / create_node).'],
                        'parent_node_token' => ['type' => 'string', 'description' => 'Parent node token.'],
                        'title' => ['type' => 'string', 'description' => 'Node title (for create_node).'],
                        'obj_type' => ['type' => 'string', 'enum' => ['docx', 'sheet', 'bitable', 'file', 'slides', 'doc', 'mindnote'], 'description' => 'Node object type (for create_node, or optional hint for resolve_url).'],
                        'url' => ['type' => 'string', 'description' => 'A full Feishu wiki URL (https://*.feishu.cn/wiki/...) to resolve. Used with action=resolve_url.'],
                        'node_token' => ['type' => 'string', 'description' => 'A pre-extracted wiki node_token. Used with action=resolve_url when the URL has already been parsed.'],
                    ],
                    'required' => ['action'],
                ],
            ],
            [
                'name' => 'drive_manage',
                'description' => 'Browse, move, or comment on Feishu Drive files.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => ['type' => 'string', 'enum' => ['list_root', 'move', 'add_comment'], 'description' => 'Drive operation.'],
                        'folder_token' => ['type' => 'string', 'description' => 'Folder token.'],
                        'file_token' => ['type' => 'string', 'description' => 'File token.'],
                        'file_type' => ['type' => 'string', 'description' => 'File type.'],
                        'doc' => ['type' => 'string', 'description' => 'Comment target or document reference.'],
                        'content' => ['type' => 'string', 'description' => 'Comment content.'],
                    ],
                    'required' => ['action'],
                ],
            ],
            [
                'name' => 'chat_history_read',
                'description' => 'Read recent Feishu chat history, or summarize communication with specific people across direct and shared chats.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'chat_id' => ['type' => 'string', 'description' => 'Optional Feishu chat id to narrow the search.'],
                        'keyword' => ['type' => 'string', 'description' => 'Optional keyword to filter matching messages.'],
                        'group_name' => ['type' => 'string', 'description' => 'Optional group name when the user refers to a specific shared chat.'],
                        'participant_names' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Names of the people whose communication with the user should be summarized.'],
                        'participant_open_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Feishu open_ids of the people whose communication with the user should be summarized.'],
                        'start_time' => ['type' => 'string', 'description' => 'ISO 8601 start time for the search window.'],
                        'end_time' => ['type' => 'string', 'description' => 'ISO 8601 end time for the search window.'],
                        'limit' => ['type' => 'integer', 'description' => 'Maximum number of messages to summarize. Default 20.'],
                    ],
                ],
            ],
            [
                'name' => 'request_authorization',
                'description' => 'Request the user to complete Feishu OAuth authorization. Call this ONLY when a previous tool call failed because of missing permissions, expired token, or insufficient OAuth scope. Do NOT call this for parameter errors, network errors, or other non-auth failures.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'reason' => ['type' => 'string', 'description' => 'User-facing explanation of why authorization is needed, in the user\'s language.'],
                        'missing_scopes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Specific capability keys that are missing, from the previous tool result missing_capabilities field.'],
                    ],
                    'required' => ['reason'],
                ],
            ],
            [
                'name' => 'load_skill',
                'description' => 'Load a skill instruction (skill.md) by key. Call this before using a skill so you can follow its specific workflow, constraints, and output format. The available skills and their purposes are listed in the Skills section of the system prompt.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'skill_key' => ['type' => 'string', 'description' => 'The skill_key from the catalog, without the leading slash.'],
                    ],
                    'required' => ['skill_key'],
                ],
            ],
            [
                'name' => 'execute_sandbox_skill',
                'description' => 'Execute a sandbox-executor skill by key. Runs the skill\'s script in an isolated environment and returns the result. Use this ONLY for skills labelled [sandbox] in the catalog; for non-sandbox skills, follow their instructions yourself after calling load_skill.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'skill_key' => ['type' => 'string', 'description' => 'The skill_key to execute, without the leading slash.'],
                        'request' => ['type' => 'string', 'description' => 'The concrete task or input for the skill, derived from the user request. The skill will extract parameters from this text as needed.'],
                    ],
                    'required' => ['skill_key', 'request'],
                ],
            ],
            [
                'name' => 'execute_api_skill',
                'description' => 'Execute an http_api-executor skill by key. Calls the configured internal API with the parameter values you extract from the user request, and returns the (filtered) response. Use this ONLY for skills labelled [api] in the catalog. The catalog lists each api skill\'s parameters (name, required, description) — read them before calling.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'skill_key' => ['type' => 'string', 'description' => 'The skill_key to execute, without the leading slash.'],
                        'request' => ['type' => 'string', 'description' => 'A JSON object string mapping each parameter (by its name or api_key from the catalog) to the value you extracted from the user request. Example: {"商品ID":"SPU-2025SUNCOAT"}. If the skill takes no parameters, pass {}.'],
                    ],
                    'required' => ['skill_key', 'request'],
                ],
            ],
        ];
    }
}
