<?php

namespace App\Services\Feishu;

use App\Services\FeishuCliClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 拉飞书多维表内容：先 `bitable/v1/apps/{token}/tables` 列 tables，
 * 再每张表 `tables/{tid}/records?page_size=50` 拉前 50 records。
 *
 * 注意：records 接口需要 `bitable:app:readonly` + `base:record:retrieve` 权限。
 * 当前 user 没授权时 records 调用会 403——降级为"只 ingest tables 列表"。
 */
class FeishuBitableFetcher
{
    private const MAX_RECORDS_PER_TABLE = 50;

    public function __construct(private readonly FeishuCliClient $cliClient) {}

    /**
     * @param  array<string,mixed>  $feishuConfig
     * @return array{ok:bool, markdown?:string, error?:string}
     */
    public function fetch(array $feishuConfig, string $openId, string $appToken): array
    {
        if ($appToken === '') {
            return ['ok' => false, 'error' => 'missing_token'];
        }
        try {
            $tablesResp = $this->cliClient->callUserApi(
                $feishuConfig, '',
                'GET',
                '/open-apis/bitable/v1/apps/' . $appToken . '/tables',
                [], $openId
            );
            if ((int) ($tablesResp['code'] ?? -1) !== 0) {
                return ['ok' => false, 'error' => 'list_tables_failed: ' . (string) ($tablesResp['msg'] ?? '')];
            }
            $tables = (array) ($tablesResp['data']['items'] ?? []);
            if (empty($tables)) {
                return ['ok' => false, 'error' => 'no_tables'];
            }

            $sections = [];
            foreach ($tables as $table) {
                $tid = trim((string) ($table['table_id'] ?? ''));
                $name = trim((string) ($table['name'] ?? ''));
                if ($tid === '') continue;

                $sec = ["## 数据表: " . ($name ?: $tid), '- table_id: ' . $tid];

                // 拉前 50 records（403 时降级）
                try {
                    $rResp = $this->cliClient->callUserApi(
                        $feishuConfig, '',
                        'GET',
                        '/open-apis/bitable/v1/apps/' . $appToken . '/tables/' . $tid . '/records?page_size=' . self::MAX_RECORDS_PER_TABLE,
                        [], $openId
                    );
                    if ((int) ($rResp['code'] ?? -1) === 0) {
                        $records = (array) ($rResp['data']['items'] ?? []);
                        if (! empty($records)) {
                            $sec[] = '';
                            $sec[] = '### Records (前 ' . count($records) . ' 条)';
                            foreach ($records as $i => $rec) {
                                $fields = (array) ($rec['fields'] ?? []);
                                $kvs = [];
                                foreach ($fields as $k => $v) {
                                    $vStr = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
                                    $kvs[] = $k . '=' . mb_substr((string) $vStr, 0, 80);
                                }
                                $sec[] = ($i + 1) . '. ' . implode(' | ', $kvs);
                            }
                        }
                    } else {
                        $code = (int) ($rResp['code'] ?? -1);
                        $sec[] = '- (records 拉取被拒：code=' . $code . '，可能缺 bitable:app:readonly 授权)';
                    }
                } catch (Throwable $e) {
                    $sec[] = '- (records 异常: ' . $e->getMessage() . ')';
                }

                $sections[] = implode("\n", $sec);
            }

            if (empty($sections)) {
                return ['ok' => false, 'error' => 'all_tables_empty'];
            }

            return ['ok' => true, 'markdown' => implode("\n\n---\n\n", $sections)];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'exception: ' . $e->getMessage()];
        }
    }
}
