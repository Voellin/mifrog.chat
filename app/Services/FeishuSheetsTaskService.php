<?php

namespace App\Services;

use App\Models\Run;
use Illuminate\Support\Facades\Log;
use Throwable;

class FeishuSheetsTaskService extends AbstractFeishuTaskService
{
    private const REQUIRED_SCOPE = 'sheets:spreadsheet';
    private const DEFAULT_TIMEOUT = 60;

    public function __construct(
        FeishuService $feishuService,
        private readonly FeishuTokenService $feishuTokenService,
        FeishuCliClient $feishuCliClient,
    ) {
        parent::__construct($feishuService, $feishuCliClient);
    }

    /**
     * OpenClaw executor: receives structured params from LLM, calls lark-cli sheets commands.
     *
     * Expected $params keys (from PlatformSkillExecutionService LLM extraction):
     *   - action: "create" | "read" | "write" | "append" | "info"
     *   - title: string (for create)
     *   - headers: string[] (for create, optional)
     *   - data: array[][] (for create/write/append, 2D array)
     *   - spreadsheet_token: string (for read/write/append/info)
     *   - spreadsheet_url: string (alternative to token)
     *   - range: string (for read/write/append, e.g. "Sheet1!A1:D10")
     *   - sheet_id: string (optional)
     *   - needs_clarification: bool
     *   - clarification_message: string
     *
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    public function execute(Run $run, array $params): array
    {
        // ── Check if LLM extraction failed ──
        if (($params['_extraction_failed'] ?? false) === true) {
            return [
                'status' => 'clarify',
                'message' => '请告诉我你想对表格做什么操作，例如"创建一个销售数据表格"或"读取这个表格的内容"。',
            ];
        }

        // ── Check if LLM says needs clarification ──
        if (($params['needs_clarification'] ?? false) === true) {
            $msg = trim((string) ($params['clarification_message'] ?? ''));
            return [
                'status' => 'clarify',
                'message' => $msg !== '' ? $msg : '请补充更多信息，例如表格标题、数据内容或表格链接。',
            ];
        }

        // ── CLI availability check (bot identity: no user token needed) ──
        if (! $this->feishuCliClient->isEnabled() || ! $this->feishuCliClient->isAvailable()) {
            return [
                'status' => 'failed',
                'message' => '飞书 CLI 工具不可用，无法执行表格操作。',
            ];
        }

        $feishuConfig = $this->feishuService->readConfig();
        $accessToken = '';  // Bot identity: token auto-obtained from app credentials
        $action = strtolower(trim((string) ($params['action'] ?? 'create')));
        $userKey = $this->resolveRunOpenId($run);

        return match ($action) {
            'create' => $this->handleCreate($run, $feishuConfig, $accessToken, $userKey, $params),
            'read' => $this->handleRead($feishuConfig, $accessToken, $userKey, $params),
            'write' => $this->handleWrite($feishuConfig, $accessToken, $userKey, $params),
            'append' => $this->handleAppend($feishuConfig, $accessToken, $userKey, $params),
            'info' => $this->handleInfo($feishuConfig, $accessToken, $userKey, $params),
            default => [
                'status' => 'clarify',
                'message' => "不支持的操作类型「{$action}」，支持的操作有：create（创建）、read（读取）、write（写入）、append（追加）、info（查看信息）。",
            ],
        };
    }

    /**
     * Create a new spreadsheet.
     */
    private function handleCreate(Run $run, array $feishuConfig, string $accessToken, string $userKey, array $params): array
    {
        $title = trim((string) ($params['title'] ?? ''));
        if ($title === '') {
            $title = '米蛙自动创建的表格';
        }

        $command = ['sheets', '+create', '--title', $title];

        // Optional headers
        $headers = $params['headers'] ?? null;
        if (is_array($headers) && $headers !== []) {
            $headersJson = json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($headersJson)) {
                $command[] = '--headers';
                $command[] = $headersJson;
            }
        }

        // Optional initial data
        $data = $params['data'] ?? null;
        if (is_array($data) && $data !== []) {
            $dataJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($dataJson)) {
                $command[] = '--data';
                $command[] = $dataJson;
            }
        }

        // Optional folder
        $folderToken = trim((string) ($params['folder_token'] ?? ''));
        if ($folderToken !== '') {
            $command[] = '--folder-token';
            $command[] = $folderToken;
        }

        // ── Prefer user identity so the document owner is the user, not the bot ──
        $usedBot = false;
        try {
            $result = $this->feishuCliClient->runSkillCommand($feishuConfig, $accessToken, $command, 'user', $userKey);
        } catch (Throwable $e) {
            $errMsg = $e->getMessage();
            $lowerErr = strtolower($errMsg);
            $isAuth = str_contains($lowerErr, 'auth') || str_contains($lowerErr, 'token') || str_contains($lowerErr, 'not logged in') || str_contains($lowerErr, 'unauthorized');

            if ($isAuth) {
                // User identity unavailable — fall back to bot identity
                Log::info('[SheetsTask] User identity unavailable, falling back to bot', ['error' => $errMsg]);
                try {
                    $result = $this->feishuCliClient->runSkillCommand($feishuConfig, $accessToken, $command, 'bot');
                    $usedBot = true;
                } catch (Throwable $e2) {
                    Log::warning('[SheetsTask] CLI create failed (bot fallback)', ['error' => $e2->getMessage(), 'params' => $params]);
                    return [
                        'status' => 'blocked',
                        'message' => '用户飞书授权已过期，需要重新授权后才能创建表格。',
                        'missing' => ['feishu.oauth.user_token', 'feishu.scope.sheets:spreadsheet:create'],
                    ];
                }
            } else {
                Log::warning('[SheetsTask] CLI create failed', ['error' => $errMsg, 'params' => $params]);
                return ['status' => 'failed', 'message' => '创建表格失败：' . $errMsg];
            }
        }

        $resultData = (array) ($result['data'] ?? []);
        $resultData['headers_written'] = is_array($headers) && $headers !== [];
        $resultData['rows_written'] = is_array($data) ? count($data) : 0;
        $result['data'] = $resultData;

        $response = $this->buildSuccessResponse('create', $result, $title);

        // Only need to share when bot created the sheet (user-created sheets are already owned by user)
        if ($usedBot && ($response['status'] ?? '') === 'success' && ($response['spreadsheet_token'] ?? '') !== '') {
            $this->shareSheetWithUser($run, $feishuConfig, $response['spreadsheet_token']);
        }

        return $response;
    }

    /**
     * Share a bot-created spreadsheet with the user who requested it.
     */
    private function shareSheetWithUser(Run $run, array $feishuConfig, string $spreadsheetToken): void
    {
        try {
            $identity = \App\Models\UserIdentity::query()
                ->where('user_id', $run->user_id)
                ->where('provider', 'feishu')
                ->first();

            $extra = is_array($identity?->extra) ? $identity->extra : [];
            $openId = trim((string) ($identity?->provider_user_id ?: ($extra['open_id'] ?? $this->resolveRunOpenId($run))));
            if ($openId === '') {
                return;
            }

            $this->feishuCliClient->callBotApi(
                $feishuConfig,
                'POST',
                "/open-apis/drive/v1/permissions/{$spreadsheetToken}/members?type=sheet&need_notification=false",
                [
                    'json' => [
                        'member_type' => 'openid',
                        'member_id' => $openId,
                        'perm' => 'full_access',
                    ],
                ]
            );

            Log::info('[SheetsTask] Shared sheet with user', [
                'spreadsheet_token' => $spreadsheetToken,
                'open_id' => $openId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SheetsTask] Failed to share sheet with user', [
                'error' => $e->getMessage(),
                'spreadsheet_token' => $spreadsheetToken,
            ]);
        }
    }

    /**
     * Read spreadsheet data.
     */
    private function handleRead(array $feishuConfig, string $accessToken, string $userKey, array $params): array
    {
        $ref = $this->resolveSpreadsheetRef($params);
        if ($ref === null) {
            return ['status' => 'clarify', 'message' => '请提供表格的链接或 token，我才能读取内容。'];
        }

        $command = ['sheets', '+read'];
        $command = array_merge($command, $ref);

        $range = trim((string) ($params['range'] ?? ''));
        $sheetId = trim((string) ($params['sheet_id'] ?? ''));
        [$sheetId, $range] = $this->resolveSheetSelection($feishuConfig, $accessToken, $userKey, $ref, $sheetId, $range);

        if ($range !== '') {
            $command[] = '--range';
            $command[] = $range;
        }

        if ($sheetId !== '') {
            $command[] = '--sheet-id';
            $command[] = $sheetId;
        }

        try {
            $result = $this->feishuCliClient->runSkillCommand($feishuConfig, $accessToken, $command, 'user', $userKey);
        } catch (Throwable $e) {
            Log::warning('[SheetsTask] CLI read failed', ['error' => $e->getMessage()]);
            $errMsg = $e->getMessage();
            $isAuth = str_contains($errMsg, '"type":"auth"') || str_contains($errMsg, 'auth') || str_contains($errMsg, 'token') || str_contains($errMsg, 'not logged in');
            return ['status' => $isAuth ? 'blocked' : 'failed', 'message' => '读取表格失败：' . $errMsg, 'missing' => $isAuth ? ['feishu.oauth.user_token', 'feishu.scope.sheets:spreadsheet'] : []];
        }

        return $this->buildSuccessResponse('read', $result, '', $params);
    }

    /**
     * Write data to spreadsheet cells (overwrite mode).
     */
    private function handleWrite(array $feishuConfig, string $accessToken, string $userKey, array $params): array
    {
        $ref = $this->resolveSpreadsheetRef($params);
        if ($ref === null) {
            return ['status' => 'clarify', 'message' => '请提供要写入的表格链接或 token。'];
        }

        $data = $params['data'] ?? $params['values'] ?? null;
        if (! is_array($data) || $data === []) {
            return ['status' => 'clarify', 'message' => '请告诉我要写入的数据内容。'];
        }

        $range = trim((string) ($params['range'] ?? ''));
        if ($range === '') {
            return ['status' => 'clarify', 'message' => '请指定写入的单元格范围，例如 "A1:D10"。'];
        }

        $sheetId = trim((string) ($params['sheet_id'] ?? ''));
        [$sheetId, $range] = $this->resolveSheetSelection($feishuConfig, $accessToken, $userKey, $ref, $sheetId, $range);

        $command = ['sheets', '+write'];
        $command = array_merge($command, $ref);
        $command[] = '--range';
        $command[] = $range;

        $valuesJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($valuesJson)) {
            $command[] = '--values';
            $command[] = $valuesJson;
        }

        if ($sheetId !== '') {
            $command[] = '--sheet-id';
            $command[] = $sheetId;
        }

        try {
            $result = $this->feishuCliClient->runSkillCommand($feishuConfig, $accessToken, $command, 'user', $userKey);
        } catch (Throwable $e) {
            Log::warning('[SheetsTask] CLI write failed', ['error' => $e->getMessage()]);
            $errMsg = $e->getMessage();
            $isAuth = str_contains($errMsg, '"type":"auth"') || str_contains($errMsg, 'auth') || str_contains($errMsg, 'token');
            return ['status' => $isAuth ? 'blocked' : 'failed', 'message' => '写入表格失败：' . $errMsg, 'missing' => $isAuth ? ['feishu.oauth.user_token'] : []];
        }

        return $this->buildSuccessResponse('write', $result, '', $params);
    }

    /**
     * Append rows to a spreadsheet.
     */
    private function handleAppend(array $feishuConfig, string $accessToken, string $userKey, array $params): array
    {
        $ref = $this->resolveSpreadsheetRef($params);
        if ($ref === null) {
            return ['status' => 'clarify', 'message' => '请提供要追加数据的表格链接或 token。'];
        }

        $data = $params['data'] ?? $params['values'] ?? null;
        if (! is_array($data) || $data === []) {
            return ['status' => 'clarify', 'message' => '请告诉我要追加的数据内容。'];
        }

        $command = ['sheets', '+append'];
        $command = array_merge($command, $ref);

        $range = trim((string) ($params['range'] ?? ''));
        $sheetId = trim((string) ($params['sheet_id'] ?? ''));
        [$sheetId, $range] = $this->resolveSheetSelection($feishuConfig, $accessToken, $userKey, $ref, $sheetId, $range);
        if ($range === '') {
            $range = $this->inferAppendRange($data);
        }
        if ($range !== '') {
            $command[] = '--range';
            $command[] = $range;
        }

        $valuesJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($valuesJson)) {
            $command[] = '--values';
            $command[] = $valuesJson;
        }

        if ($sheetId !== '') {
            $command[] = '--sheet-id';
            $command[] = $sheetId;
        }

        try {
            $result = $this->feishuCliClient->runSkillCommand($feishuConfig, $accessToken, $command, 'user', $userKey);
        } catch (Throwable $e) {
            Log::warning('[SheetsTask] CLI append failed', ['error' => $e->getMessage()]);
            $errMsg = $e->getMessage();
            $isAuth = str_contains($errMsg, '"type":"auth"') || str_contains($errMsg, 'auth') || str_contains($errMsg, 'token');
            return ['status' => $isAuth ? 'blocked' : 'failed', 'message' => '追加数据失败：' . $errMsg, 'missing' => $isAuth ? ['feishu.oauth.user_token'] : []];
        }

        return $this->buildSuccessResponse('append', $result, '', $params);
    }

    /**
     * Get spreadsheet info.
     */
    private function handleInfo(array $feishuConfig, string $accessToken, string $userKey, array $params): array
    {
        $ref = $this->resolveSpreadsheetRef($params);
        if ($ref === null) {
            return ['status' => 'clarify', 'message' => '请提供要查看的表格链接或 token。'];
        }

        $command = ['sheets', '+info'];
        $command = array_merge($command, $ref);

        try {
            $result = $this->feishuCliClient->runSkillCommand($feishuConfig, $accessToken, $command, 'user', $userKey);
        } catch (Throwable $e) {
            Log::warning('[SheetsTask] CLI info failed', ['error' => $e->getMessage()]);
            $errMsg = $e->getMessage();
            $isAuth = str_contains($errMsg, '"type":"auth"') || str_contains($errMsg, 'auth') || str_contains($errMsg, 'token');
            return ['status' => $isAuth ? 'blocked' : 'failed', 'message' => '获取表格信息失败：' . $errMsg, 'missing' => $isAuth ? ['feishu.oauth.user_token'] : []];
        }

        return $this->buildSuccessResponse('info', $result, '', $params);
    }

    // ─── Helpers ──────────────────────────────────────────────

    /**
     * Resolve spreadsheet reference from params (--url or --spreadsheet-token).
     *
     * @return string[]|null  CLI flag pair, e.g. ['--url', 'https://...'] or ['--spreadsheet-token', 'xxx']
     */
    private function resolveSpreadsheetRef(array $params): ?array
    {
        $url = trim((string) ($params['spreadsheet_url'] ?? ($params['url'] ?? '')));
        if ($url !== '' && (str_contains($url, 'feishu.cn') || str_contains($url, 'larksuite.com'))) {
            return ['--url', $url];
        }

        $token = trim((string) ($params['spreadsheet_token'] ?? ($params['token'] ?? '')));
        if ($token !== '') {
            return ['--spreadsheet-token', $token];
        }

        // Try extracting token from URL-like string
        if ($url !== '' && preg_match('/[A-Za-z0-9]{20,}/', $url, $m)) {
            return ['--spreadsheet-token', $m[0]];
        }

        return null;
    }

    /**
     * @param  array<int, string>  $ref
     * @return array{0:string,1:string}
     */
    private function resolveSheetSelection(
        array $feishuConfig,
        string $accessToken,
        string $userKey,
        array $ref,
        string $sheetId,
        string $range
    ): array {
        $sheetId = trim($sheetId);
        $range = trim($range);
        $requestedSheet = '';

        if ($range !== '' && str_contains($range, '!')) {
            [$prefix, $rest] = explode('!', $range, 2);
            $prefix = trim($prefix);
            $rest = trim($rest);
            if ($prefix !== '' && $rest !== '') {
                $requestedSheet = $prefix;
                $range = $rest;
            }
        }

        if ($sheetId !== '' && $requestedSheet === '') {
            return [$sheetId, $range];
        }

        $sheets = $this->fetchSheetList($feishuConfig, $accessToken, $userKey, $ref);
        if ($sheets === []) {
            return [$sheetId, $range];
        }

        foreach ($sheets as $sheet) {
            $candidateId = trim((string) ($sheet['sheet_id'] ?? ''));
            $candidateTitle = trim((string) ($sheet['title'] ?? ''));
            if ($candidateId === '') {
                continue;
            }

            if ($sheetId !== '' && $candidateId === $sheetId) {
                return [$sheetId, $range];
            }

            if ($requestedSheet !== '' && ($candidateId === $requestedSheet || $candidateTitle === $requestedSheet)) {
                return [$candidateId, $range];
            }
        }

        if ($sheetId === '' && count($sheets) === 1) {
            $firstId = trim((string) ($sheets[0]['sheet_id'] ?? ''));
            if ($firstId !== '') {
                return [$firstId, $range];
            }
        }

        return [$sheetId, $range];
    }

    /**
     * @param  array<int, string>  $ref
     * @return array<int, array<string, mixed>>
     */
    private function fetchSheetList(array $feishuConfig, string $accessToken, string $userKey, array $ref): array
    {
        $command = array_merge(['sheets', '+info'], $ref);

        try {
            $result = $this->feishuCliClient->runSkillCommand($feishuConfig, $accessToken, $command, 'user', $userKey);
        } catch (Throwable $e) {
            Log::warning('[SheetsTask] Sheet info lookup failed', ['error' => $e->getMessage()]);

            return [];
        }

        if ((int) ($result['code'] ?? 0) !== 0) {
            return [];
        }

        $data = (array) ($result['data'] ?? $result);
        $sheets = $data['sheets']['sheets'] ?? ($data['sheets'] ?? []);

        return array_values(array_filter((array) $sheets, 'is_array'));
    }

    /**
     * @param  array<int, mixed>  $data
     */
    private function inferAppendRange(array $data): string
    {
        $firstRow = $data[0] ?? null;
        if (! is_array($firstRow) || $firstRow === []) {
            return '';
        }

        $columnCount = count($firstRow);
        if ($columnCount <= 0) {
            return '';
        }

        return 'A:' . $this->columnNumberToLetters($columnCount);
    }

    private function columnNumberToLetters(int $columnNumber): string
    {
        $columnNumber = max(1, $columnNumber);
        $letters = '';

        while ($columnNumber > 0) {
            $columnNumber--;
            $letters = chr(65 + ($columnNumber % 26)) . $letters;
            $columnNumber = intdiv($columnNumber, 26);
        }

        return $letters;
    }

    /**
     * Build a standardized success response.
     */
    private function buildSuccessResponse(string $action, array $cliResult, string $title = '', array $context = []): array
    {
        // Check for API-level errors in CLI output
        $code = (int) ($cliResult['code'] ?? 0);
        if ($code !== 0) {
            $msg = (string) ($cliResult['msg'] ?? 'unknown_error');
            $errorType = strtolower(trim((string) ($cliResult['error_type'] ?? '')));
            $lowerMsg = strtolower($msg);

            // Detect auth/token errors and return blocked status
            $isAuth = in_array($errorType, ['auth', 'token', 'permission', 'config'], true)
                || str_contains($lowerMsg, 'not logged in')
                || str_contains($lowerMsg, 'not configured')
                || str_contains($lowerMsg, 'auth')
                || str_contains($lowerMsg, '授权');

            Log::warning('[SheetsTask] API error', ['action' => $action, 'code' => $code, 'msg' => $msg, 'is_auth' => $isAuth]);
            return [
                'status' => $isAuth ? 'blocked' : 'failed',
                'message' => $isAuth ? "需要飞书授权：{$msg}" : "表格操作失败（错误码 {$code}）：{$msg}",
                'missing' => $isAuth ? ['feishu.oauth.user_token'] : [],
            ];
        }

        $data = (array) ($cliResult['data'] ?? $cliResult);

        $response = [
            'status' => 'success',
            'action' => $action,
            'raw_data' => $data,
        ];
        $resolvedToken = trim((string) ($context['spreadsheet_token'] ?? ($context['token'] ?? '')));
        $resolvedUrl = trim((string) ($context['spreadsheet_url'] ?? ($context['url'] ?? '')));
        $resolvedTitle = trim((string) ($context['title'] ?? ''));

        // Extract useful fields for reporting
        switch ($action) {
            case 'create':
                $spreadsheetToken = (string) ($data['spreadsheet']['spreadsheet_token']
                    ?? $data['spreadsheetToken']
                    ?? $data['spreadsheet_token']
                    ?? '');
                $url = (string) ($data['spreadsheet']['url']
                    ?? $data['url']
                    ?? '');
                if ($url === '' && $spreadsheetToken !== '') {
                    $url = 'https://mifrog.feishu.cn/sheets/' . $spreadsheetToken;
                }
                $response['message'] = $title !== ''
                    ? "已创建表格「{$title}」"
                    : '已创建表格';
                if (($data['headers_written'] ?? false) === true) {
                    $response['message'] .= '，已写入表头';
                }
                $rowsWritten = (int) ($data['rows_written'] ?? 0);
                if ($rowsWritten > 0) {
                    $response['message'] .= "，已写入 {$rowsWritten} 行数据";
                }
                if ($url !== '') {
                    $response['message'] .= "，链接：{$url}";
                    $response['url'] = $url;
                }
                $response['spreadsheet_token'] = $spreadsheetToken;
                $sheetList = array_values(array_filter((array) ($data['sheets']['sheets'] ?? ($data['sheets'] ?? [])), 'is_array'));
                $firstSheet = $sheetList[0] ?? [];
                if (($firstSheet['sheet_id'] ?? '') !== '') {
                    $response['sheet_id'] = (string) $firstSheet['sheet_id'];
                }
                if (($firstSheet['title'] ?? '') !== '') {
                    $response['sheet_title'] = (string) $firstSheet['title'];
                }
                $response['title'] = $title;
                break;

            case 'read':
                $rawOutput = (string) ($cliResult['_raw_output'] ?? '');
                $values = $data['valueRange']['values']
                    ?? $data['values']
                    ?? $data['valueRanges']
                    ?? null;
                if (is_array($values)) {
                    $rowCount = count($values);
                    $response['message'] = "已读取 {$rowCount} 行数据。";
                    $response['values'] = $values;
                } elseif ($rawOutput !== '') {
                    $response['message'] = '已读取表格内容。';
                    $response['content'] = $rawOutput;
                } else {
                    $response['message'] = '已读取表格，但没有找到数据。';
                }
                if ($resolvedToken !== '') {
                    $response['spreadsheet_token'] = $resolvedToken;
                }
                if ($resolvedUrl !== '') {
                    $response['url'] = $resolvedUrl;
                }
                if ($resolvedTitle !== '') {
                    $response['title'] = $resolvedTitle;
                }
                break;

            case 'write':
                $response['message'] = '已成功写入数据到表格。';
                if ($resolvedToken !== '') {
                    $response['spreadsheet_token'] = $resolvedToken;
                }
                if ($resolvedUrl !== '') {
                    $response['url'] = $resolvedUrl;
                }
                if ($resolvedTitle !== '') {
                    $response['title'] = $resolvedTitle;
                }
                break;

            case 'append':
                $response['message'] = '已成功追加数据到表格。';
                if ($resolvedToken !== '') {
                    $response['spreadsheet_token'] = $resolvedToken;
                }
                if ($resolvedUrl !== '') {
                    $response['url'] = $resolvedUrl;
                }
                if ($resolvedTitle !== '') {
                    $response['title'] = $resolvedTitle;
                }
                break;

            case 'info':
                $sheetTitle = (string) ($data['spreadsheet']['title']
                    ?? $data['title']
                    ?? '');
                $sheetCount = count($data['sheets'] ?? []);
                $response['message'] = $sheetTitle !== ''
                    ? "表格「{$sheetTitle}」共有 {$sheetCount} 个工作表。"
                    : "表格共有 {$sheetCount} 个工作表。";
                if ($resolvedToken !== '') {
                    $response['spreadsheet_token'] = $resolvedToken;
                }
                if ($resolvedUrl !== '') {
                    $response['url'] = $resolvedUrl;
                }
                if ($sheetTitle !== '') {
                    $response['title'] = $sheetTitle;
                } elseif ($resolvedTitle !== '') {
                    $response['title'] = $resolvedTitle;
                }
                break;
        }

        return $response;
    }

}
