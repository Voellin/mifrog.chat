<?php

namespace App\Services\Feishu;

use App\Services\FeishuCliClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 调 lark-cli `sheets +info` 拉所有工作表，再对每张工作表 `sheets +read` 拉前 200 行 × 20 列 cells，
 * 拼成 markdown 表给 ActivityArchiveKernel.ingestSheets 用。
 */
class FeishuSheetContentFetcher
{
    /** 第一版：每张 sheet 取 200 行 × 20 列 */
    private const MAX_ROWS = 200;
    private const MAX_COLS = 20;

    public function __construct(private readonly FeishuCliClient $cliClient) {}

    /**
     * @param  array<string,mixed>  $feishuConfig
     * @return array{ok:bool, title?:string, markdown?:string, error?:string}
     */
    public function fetch(array $feishuConfig, string $openId, string $spreadsheetToken): array
    {
        if ($spreadsheetToken === '') {
            return ['ok' => false, 'error' => 'missing_token'];
        }

        try {
            $info = $this->cliClient->runSkillCommand(
                $feishuConfig, '',
                ['sheets', '+info', '--spreadsheet-token', $spreadsheetToken],
                'user', $openId
            );
            if (! ($info['ok'] ?? false)) {
                return ['ok' => false, 'error' => 'info_failed'];
            }
            $title = trim((string) ($info['data']['spreadsheet']['spreadsheet']['title'] ?? ''));
            $sheets = (array) ($info['data']['sheets']['sheets'] ?? []);
            if (empty($sheets)) {
                return ['ok' => false, 'error' => 'no_sheets'];
            }

            $sections = [];
            foreach ($sheets as $sheet) {
                $sheetId = trim((string) ($sheet['sheet_id'] ?? ''));
                $sheetTitle = trim((string) ($sheet['title'] ?? ''));
                if ($sheetId === '') continue;

                // A1 到 第 MAX_COLS 列 (A=1, T=20) 第 MAX_ROWS 行
                $endCol = $this->colIndexToLetter(self::MAX_COLS); // 'T'
                $range = $sheetId . '!A1:' . $endCol . self::MAX_ROWS;

                try {
                    $r = $this->cliClient->runSkillCommand(
                        $feishuConfig, '',
                        [
                            'sheets', '+read',
                            '--spreadsheet-token', $spreadsheetToken,
                            '--range', $range,
                            '--value-render-option', 'ToString',
                        ],
                        'user', $openId
                    );
                } catch (Throwable $e) {
                    Log::info('[FeishuSheetContentFetcher] sheet read failed', [
                        'sheet_id' => $sheetId, 'error' => $e->getMessage(),
                    ]);
                    continue;
                }

                if (! ($r['ok'] ?? false)) continue;

                $values = (array) ($r['data']['valueRange']['values'] ?? []);
                $md = $this->valuesToMarkdown($sheetTitle, $values);
                if ($md !== '') $sections[] = $md;
            }

            if (empty($sections)) {
                return ['ok' => false, 'error' => 'all_sheets_empty'];
            }

            return [
                'ok' => true,
                'title' => $title,
                'markdown' => implode("\n\n---\n\n", $sections),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'exception: ' . $e->getMessage()];
        }
    }

    /**
     * @param array<int, array<int, mixed>> $values
     */
    private function valuesToMarkdown(string $sheetTitle, array $values): string
    {
        // 去掉尾部全空行
        $cleaned = [];
        foreach ($values as $row) {
            if (! is_array($row)) continue;
            $hasContent = false;
            foreach ($row as $c) {
                if ($c !== null && trim((string) $c) !== '') {
                    $hasContent = true;
                    break;
                }
            }
            if ($hasContent) {
                $cleaned[] = $row;
            }
        }
        if (empty($cleaned)) {
            return '';
        }

        $lines = ["## 工作表: " . $sheetTitle, ''];
        foreach ($cleaned as $row) {
            // 每行用 | 拼，超长截断到 80 字符
            $cells = [];
            foreach ($row as $c) {
                $s = $c === null ? '' : (string) $c;
                $s = str_replace(['|', "\n", "\r"], [' ', ' ', ''], $s);
                $cells[] = mb_substr($s, 0, 80);
            }
            $lines[] = '| ' . implode(' | ', $cells) . ' |';
        }
        return implode("\n", $lines);
    }

    private function colIndexToLetter(int $n): string
    {
        // 1 -> A, 26 -> Z, 27 -> AA
        $s = '';
        while ($n > 0) {
            $n--;
            $s = chr(65 + ($n % 26)) . $s;
            $n = intdiv($n, 26);
        }
        return $s;
    }
}
