<?php

namespace App\Services;

use App\Models\Run;
use Throwable;

class FeishuBaseTaskService extends AbstractFeishuTaskService
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
                'message' => 'Please tell me whether you want to create, inspect, query, or update a Feishu Base.',
            ];
        }

        if (($params['needs_clarification'] ?? false) === true) {
            $message = trim((string) ($params['clarification_message'] ?? ''));

            return [
                'status' => 'clarify',
                'message' => $message !== '' ? $message : 'Please provide the target base link or token for this Base operation.',
            ];
        }

        if (! $this->feishuCliClient->isEnabled() || ! $this->feishuCliClient->isAvailable()) {
            return [
                'status' => 'failed',
                'message' => 'Feishu CLI is not available, so Base operations cannot run right now.',
            ];
        }

        $config = $this->feishuService->readConfig();
        $action = strtolower(trim((string) ($params['action'] ?? 'create_base')));
        $command = $this->buildCommand($action, $params);
        $userKey = $this->resolveRunOpenId($run);
        if ($command === null) {
            return [
                'status' => 'clarify',
                'message' => 'Please provide the target base link or token, and the specific table or record details that you want me to use.',
            ];
        }

        try {
            $result = $this->feishuCliClient->runSkillCommand($config, '', $command, 'user', $userKey);
        } catch (Throwable $e) {
            return $this->blockedFromThrowable($e, 'Feishu authorization is required before I can continue with Base operations.');
        }

        if ($action === 'create_table') {
            return $this->handleCreateTableResult($config, $userKey, $result, $params);
        }

        $code = (int) ($result['code'] ?? 0);
        if ($code !== 0) {
            $msg = trim((string) ($result['msg'] ?? 'base_operation_failed'));

            return [
                'status' => 'failed',
                'message' => 'Base operation failed: ' . $msg,
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
            'create_base' => $this->buildCreateBaseCommand($params),
            'create_table' => $this->buildCreateTableCommand($params),
            'list_tables' => $this->buildListTablesCommand($params),
            'list_records' => $this->buildListRecordsCommand($params),
            'query_data' => $this->buildQueryDataCommand($params),
            'upsert_record' => $this->buildUpsertRecordCommand($params),
            default => null,
        };
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>
     */
    private function buildCreateBaseCommand(array $params): array
    {
        $name = trim((string) ($params['base_name'] ?? ''));
        if ($name === '') {
            $name = 'MiFrog Base ' . now()->format('Ymd_His');
        }

        $timeZone = trim((string) ($params['time_zone'] ?? ''));
        if ($timeZone === '') {
            $timeZone = 'Asia/Shanghai';
        }

        return ['base', '+base-create', '--name', $name, '--time-zone', $timeZone];
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>|null
     */
    private function buildCreateTableCommand(array $params): ?array
    {
        $baseToken = $this->resolveBaseToken($params);
        $tableName = trim((string) ($params['table_name'] ?? ''));
        if ($baseToken === '' || $tableName === '') {
            return null;
        }

        $command = ['base', '+table-create', '--base-token', $baseToken, '--name', $tableName];

        $fields = $params['fields'] ?? null;
        if (is_array($fields) && $fields !== []) {
            $normalizedFields = $this->normalizeFields($fields);
            $fieldsJson = json_encode($normalizedFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($fieldsJson)) {
                $command[] = '--fields';
                $command[] = $fieldsJson;
            }
        }

        return $command;
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>|null
     */
    private function buildListTablesCommand(array $params): ?array
    {
        $baseToken = $this->resolveBaseToken($params);
        if ($baseToken === '') {
            return null;
        }

        return ['base', '+table-list', '--base-token', $baseToken];
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>|null
     */
    private function buildListRecordsCommand(array $params): ?array
    {
        $baseToken = $this->resolveBaseToken($params);
        $tableId = $this->resolveTableId($params);
        if ($baseToken === '' || $tableId === '') {
            return null;
        }

        $command = ['base', '+record-list', '--base-token', $baseToken, '--table-id', $tableId];
        $limit = max(1, min(500, (int) ($params['limit'] ?? 100)));
        $offset = max(0, (int) ($params['offset'] ?? 0));

        $command[] = '--limit';
        $command[] = (string) $limit;
        if ($offset > 0) {
            $command[] = '--offset';
            $command[] = (string) $offset;
        }

        return $command;
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>|null
     */
    private function buildQueryDataCommand(array $params): ?array
    {
        $baseToken = $this->resolveBaseToken($params);
        $dsl = $params['dsl'] ?? null;
        if ($baseToken === '' || ! is_array($dsl) || $dsl === []) {
            return null;
        }

        $dslJson = json_encode($dsl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($dslJson)) {
            return null;
        }

        return ['base', '+data-query', '--base-token', $baseToken, '--dsl', $dslJson];
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>|null
     */
    private function buildUpsertRecordCommand(array $params): ?array
    {
        $baseToken = $this->resolveBaseToken($params);
        $tableId = $this->resolveTableId($params);
        $record = $params['record'] ?? null;
        if ($baseToken === '' || $tableId === '' || ! is_array($record) || $record === []) {
            return null;
        }

        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            return null;
        }

        $command = ['base', '+record-upsert', '--base-token', $baseToken, '--table-id', $tableId, '--json', $json];
        $recordId = trim((string) ($params['record_id'] ?? ''));
        if ($recordId !== '') {
            $command[] = '--record-id';
            $command[] = $recordId;
        }

        return $command;
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function handleCreateTableResult(array $config, string $userKey, array $result, array $params): array
    {
        $code = (int) ($result['code'] ?? 0);
        if ($code === 0) {
            $response = $this->buildSuccessResponse('create_table', $result, $params);

            return $this->ensureRequestedFieldsExist($config, $userKey, $params, $response);
        }

        $recovered = $this->recoverCreateTableResult($config, $userKey, $params);
        if ($recovered !== null) {
            return $recovered;
        }

        $msg = trim((string) ($result['msg'] ?? 'base_operation_failed'));

        return [
            'status' => 'failed',
            'message' => 'Base operation failed: ' . $msg,
            'error' => $result,
        ];
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
            'create_base' => $this->buildCreateBaseResponse($data),
            'create_table' => $this->buildCreateTableResponse($data, $params),
            'list_tables' => $this->buildListTablesResponse($data),
            'list_records' => $this->buildListRecordsResponse($data),
            'query_data' => $this->buildQueryDataResponse($data),
            'upsert_record' => [
                'status' => 'success',
                'message' => 'Base record written successfully.',
                'model' => 'feishu-base-upsert-record',
                'raw_data' => $data,
            ],
            default => [
                'status' => 'success',
                'message' => 'Base operation completed successfully.',
                'model' => 'feishu-base',
                'raw_data' => $data,
            ],
        };
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function buildCreateBaseResponse(array $data): array
    {
        $base = (array) ($data['base'] ?? $data);
        $name = trim((string) ($base['name'] ?? ''));
        $url = trim((string) ($base['url'] ?? ''));
        $token = trim((string) ($base['base_token'] ?? ''));

        $message = $name !== '' ? 'Created Feishu Base "' . $name . '"' : 'Created a Feishu Base';
        if ($url !== '') {
            $message .= '. Link: ' . $url;
        }
        if ($token !== '') {
            $message .= '. Base token: ' . $token;
        }

        return [
            'status' => 'success',
            'message' => $message,
            'model' => 'feishu-base-create',
            'base_token' => $token,
            'url' => $url,
            'base_name' => $name,
            'raw_data' => $data,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function buildCreateTableResponse(array $data, array $params): array
    {
        $table = (array) ($data['table'] ?? $data);
        $tableName = trim((string) ($table['name'] ?? ($table['table_name'] ?? ($params['table_name'] ?? ''))));
        $tableId = trim((string) ($table['table_id'] ?? ($table['id'] ?? '')));
        $message = $tableName !== '' ? 'Created Base table "' . $tableName . '"' : 'Created a Base table';
        if ($tableId !== '') {
            $message .= '. table_id=' . $tableId;
        }

        return [
            'status' => 'success',
            'message' => $message,
            'model' => 'feishu-base-create-table',
            'table_id' => $tableId,
            'table_name' => $tableName,
            'raw_data' => $data,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function buildListTablesResponse(array $data): array
    {
        $tables = array_values(array_filter(
            (array) ($data['items'] ?? ($data['tables'] ?? [])),
            'is_array'
        ));

        if ($tables === []) {
            return [
                'status' => 'success',
                'message' => 'This Base does not contain any tables yet.',
                'model' => 'feishu-base-list-tables',
                'raw_data' => $data,
            ];
        }

        $lines = ['Tables in this Base:'];
        foreach (array_slice($tables, 0, 10) as $index => $table) {
            $name = trim((string) ($table['table_name'] ?? ($table['name'] ?? 'Untitled table')));
            $tableId = trim((string) ($table['table_id'] ?? ($table['id'] ?? '')));
            $lines[] = ($index + 1) . '. ' . $name . ($tableId !== '' ? ' (table_id=' . $tableId . ')' : '');
        }

        return [
            'status' => 'success',
            'message' => implode("\n", $lines),
            'model' => 'feishu-base-list-tables',
            'raw_data' => $data,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function buildListRecordsResponse(array $data): array
    {
        $records = array_values(array_filter(
            (array) ($data['items'] ?? ($data['records'] ?? ($data['data'] ?? []))),
            'is_array'
        ));
        $count = count($records);
        $message = 'Listed ' . $count . ' records from the Base table.';
        if ($count > 0) {
            $fields = array_values(array_filter(
                array_map(
                    static fn ($field): string => trim((string) $field),
                    (array) ($data['fields'] ?? [])
                ),
                static fn (string $field): bool => $field !== ''
            ));
            $previewRecords = [];
            foreach (array_slice($records, 0, 3) as $record) {
                if ($fields !== [] && array_is_list($record)) {
                    $previewRecords[] = array_combine(
                        array_slice($fields, 0, count($record)),
                        array_map(
                            static fn ($value) => is_scalar($value) || $value === null ? (string) $value : '',
                            $record
                        )
                    ) ?: $record;
                    continue;
                }

                $previewRecords[] = $record;
            }

            $preview = json_encode($previewRecords, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($preview) && $preview !== '') {
                $message .= "\nPreview: " . $preview;
            }
        }

        return [
            'status' => 'success',
            'message' => $message,
            'model' => 'feishu-base-list-records',
            'raw_data' => $data,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function buildQueryDataResponse(array $data): array
    {
        $preview = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($preview) || $preview === '') {
            $preview = '{}';
        }

        return [
            'status' => 'success',
            'message' => 'Base query completed. Result: ' . $this->truncate($preview, 1200),
            'model' => 'feishu-base-query',
            'raw_data' => $data,
        ];
    }

    /**
     * @param  array<string,mixed>  $params
     */
    private function resolveBaseToken(array $params): string
    {
        $baseToken = trim((string) ($params['base_token'] ?? ''));
        if ($baseToken !== '') {
            return $baseToken;
        }

        $baseUrl = trim((string) ($params['base_url'] ?? ''));
        if ($baseUrl !== '' && preg_match('#/base/([A-Za-z0-9]+)#', $baseUrl, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $params
     */
    private function resolveTableId(array $params): string
    {
        $tableId = trim((string) ($params['table_id'] ?? ''));
        if ($tableId !== '') {
            return $tableId;
        }

        return trim((string) ($params['table_name'] ?? ''));
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>|null
     */
    private function recoverCreateTableResult(array $config, string $userKey, array $params): ?array
    {
        $baseToken = $this->resolveBaseToken($params);
        $tableName = trim((string) ($params['table_name'] ?? ''));
        if ($baseToken === '' || $tableName === '') {
            return null;
        }

        $table = $this->findTableByName($config, $userKey, $baseToken, $tableName);
        if ($table === []) {
            return null;
        }

        $response = $this->buildCreateTableResponse([
            'table' => [
                'id' => (string) ($table['table_id'] ?? ($table['id'] ?? '')),
                'name' => (string) ($table['table_name'] ?? ($table['name'] ?? $tableName)),
            ],
        ], $params);
        $response = $this->ensureRequestedFieldsExist($config, $userKey, $params, $response);
        $response['message'] .= ' Field sync was recovered after the table appeared in Base.';

        return $response;
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>  $params
     * @param  array<string,mixed>  $response
     * @return array<string,mixed>
     */
    private function ensureRequestedFieldsExist(array $config, string $userKey, array $params, array $response): array
    {
        $requestedFields = $this->normalizeFields((array) ($params['fields'] ?? []));
        if ($requestedFields === []) {
            return $response;
        }

        $baseToken = $this->resolveBaseToken($params);
        $tableId = trim((string) ($response['table_id'] ?? ''));
        $tableName = trim((string) ($response['table_name'] ?? ($params['table_name'] ?? '')));
        if ($baseToken === '') {
            return $response;
        }

        if ($tableId === '' && $tableName !== '') {
            $table = $this->findTableByName($config, $userKey, $baseToken, $tableName);
            $tableId = trim((string) ($table['table_id'] ?? ($table['id'] ?? '')));
        }

        if ($tableId === '') {
            return $response;
        }

        $existingFields = $this->listFields($config, $userKey, $baseToken, $tableId);
        $existingNames = array_map(
            static fn (array $field): string => trim((string) ($field['field_name'] ?? ($field['name'] ?? ''))),
            $existingFields
        );
        $existingNames = array_values(array_filter($existingNames, static fn (string $name): bool => $name !== ''));

        $addedFields = [];
        foreach ($requestedFields as $field) {
            $fieldName = trim((string) ($field['field_name'] ?? ''));
            if ($fieldName === '' || in_array($fieldName, $existingNames, true)) {
                continue;
            }

            if (! $this->createField($config, $userKey, $baseToken, $tableId, $field)) {
                continue;
            }

            $existingNames[] = $fieldName;
            $addedFields[] = $fieldName;
        }

        if ($addedFields !== []) {
            $response['message'] .= ' Added missing fields: ' . implode(', ', $addedFields) . '.';
        }

        $response['table_id'] = $tableId;
        if ($tableName !== '') {
            $response['table_name'] = $tableName;
        }

        return $response;
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    private function findTableByName(array $config, string $userKey, string $baseToken, string $tableName): array
    {
        try {
            $result = $this->feishuCliClient->runSkillCommand(
                $config,
                '',
                ['base', '+table-list', '--base-token', $baseToken],
                'user',
                $userKey
            );
        } catch (Throwable) {
            return [];
        }

        $items = array_values(array_filter(
            (array) ($result['data']['items'] ?? ($result['data']['tables'] ?? [])),
            'is_array'
        ));

        foreach ($items as $item) {
            $candidateName = trim((string) ($item['table_name'] ?? ($item['name'] ?? '')));
            if ($candidateName === $tableName) {
                return $item;
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array<int, array<string,mixed>>
     */
    private function listFields(array $config, string $userKey, string $baseToken, string $tableId): array
    {
        try {
            $result = $this->feishuCliClient->runSkillCommand(
                $config,
                '',
                ['base', '+field-list', '--base-token', $baseToken, '--table-id', $tableId],
                'user',
                $userKey
            );
        } catch (Throwable) {
            return [];
        }

        return array_values(array_filter(
            (array) ($result['data']['items'] ?? []),
            'is_array'
        ));
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,string>  $field
     */
    private function createField(array $config, string $userKey, string $baseToken, string $tableId, array $field): bool
    {
        $json = json_encode($field, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json) || $json === '') {
            return false;
        }

        try {
            $result = $this->feishuCliClient->runSkillCommand(
                $config,
                '',
                ['base', '+field-create', '--base-token', $baseToken, '--table-id', $tableId, '--json', $json],
                'user',
                $userKey
            );
        } catch (Throwable) {
            return false;
        }

        return (($result['ok'] ?? false) === true) || ((int) ($result['code'] ?? 0) === 0);
    }

    /**
     * @param  array<int, mixed>  $fields
     * @return array<int, array<string, string>>
     */
    private function normalizeFields(array $fields): array
    {
        $normalized = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $name = trim((string) ($field['field_name'] ?? ($field['name'] ?? '')));
            if ($name === '') {
                continue;
            }

            $type = $this->normalizeFieldType((string) ($field['type'] ?? ($field['field_type'] ?? 'text')));
            $normalized[] = [
                'field_name' => $name,
                'type' => $type,
            ];
        }

        return $normalized;
    }

    private function normalizeFieldType(string $type): string
    {
        return match (mb_strtolower(trim($type), 'UTF-8')) {
            'text', 'string', 'varchar', 'plain_text', 'rich_text', '文本', '文字', '单行文本' => 'text',
            'number', 'int', 'integer', 'float', 'double', '数值', '数字' => 'number',
            'single_select', 'select', 'singlechoice', '单选' => 'single_select',
            'multi_select', 'multiselect', '多选' => 'multi_select',
            'checkbox', 'bool', 'boolean', '复选框' => 'checkbox',
            'datetime', 'date_time', 'date', '时间', '日期' => 'datetime',
            default => 'text',
        };
    }




}
