<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\AttachmentChunk;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Run;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ZipArchive;

class AttachmentService
{
    private FeishuService $feishuService;
    private LlmGatewayService $llmGatewayService;
    private UserWorkspaceService $workspaceService;

    public function __construct(
        FeishuService $feishuService,
        LlmGatewayService $llmGatewayService,
        UserWorkspaceService $workspaceService
    ) {
        $this->feishuService = $feishuService;
        $this->llmGatewayService = $llmGatewayService;
        $this->workspaceService = $workspaceService;
    }

    public function registerInboundAttachments(
        User $user,
        Conversation $conversation,
        Message $message,
        array $attachments,
        array $context = []
    ): array {
        if (empty($attachments)) {
            return [];
        }

        $this->workspaceService->ensure((int) $user->id);
        $sourceChannel = (string) ($context['channel'] ?? 'feishu');
        $sourceMessageId = trim((string) ($context['source_message_id'] ?? ''));

        $created = [];
        foreach ($attachments as $item) {
            $fileKey = trim((string) ($item['file_key'] ?? ''));
            $attachmentType = strtolower(trim((string) ($item['type'] ?? 'file')));
            if ($fileKey === '') {
                continue;
            }

            $row = Attachment::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'source_channel' => $sourceChannel,
                    'source_message_id' => $sourceMessageId,
                    'file_key' => $fileKey,
                ],
                [
                    'conversation_id' => $conversation->id,
                    'message_id' => $message->id,
                    'attachment_type' => $attachmentType,
                    'file_name' => $this->normalizeFileName((string) ($item['file_name'] ?? '')),
                    'file_ext' => strtolower(trim((string) ($item['file_ext'] ?? ''))),
                    'mime_type' => trim((string) ($item['mime_type'] ?? '')) ?: null,
                    'file_size' => $this->toIntOrNull($item['file_size'] ?? null),
                    'parse_status' => Attachment::STATUS_QUEUED,
                    'meta' => [
                        'inbound_message_type' => (string) ($item['message_type'] ?? ''),
                        'source_content' => (array) ($item['source_content'] ?? []),
                    ],
                ]
            );

            if (! $row->wasRecentlyCreated) {
                $row->conversation_id = $conversation->id;
                $row->message_id = $message->id;
                $row->attachment_type = $attachmentType ?: $row->attachment_type;
                $row->file_name = $row->file_name ?: $this->normalizeFileName((string) ($item['file_name'] ?? ''));
                $row->file_ext = $row->file_ext ?: strtolower(trim((string) ($item['file_ext'] ?? '')));
                $row->mime_type = $row->mime_type ?: (trim((string) ($item['mime_type'] ?? '')) ?: null);
                if ($row->parse_status === Attachment::STATUS_FAILED) {
                    $row->parse_status = Attachment::STATUS_QUEUED;
                    $row->parse_error = null;
                }
                $row->save();
            }

            $created[] = $row->id;
        }

        return $created;
    }

    /**
     * 把"用户在飞书侧浏览/编辑过的远程资源（文档/表格/邮件等）"的正文 ingest 到用户知识库。
     * 跟 user 主动上传文件走同一个 attachments + attachment_chunks 表，区别只在 attachment_type
     * （'auto_archive'）和 source_channel（'auto_archive'）。
     *
     * 设计：
     *  - file_key = sourceKey（飞书 doc_token / sheet_token / mail_id）→ 同一资源后续 fetch
     *    可命中已存 attachment
     *  - content_hash = sha1(content) → 同 hash 跳过（防止反复存同样内容）
     *  - chunks 上限 100（按 Lin 拍板：避免 50 页报告把表撑爆）
     *  - storage_path 留 NULL（远程资源不落本地文件，只存切片）
     *
     * @return array{attachment_id:int, skipped:?string, chunk_count:int}
     */
    public function ingestRemoteDocument(
        int $userId,
        string $sourceKind,
        string $sourceKey,
        string $title,
        string $content,
        string $mimeType = 'text/markdown'
    ): array {
        $sourceKey = trim($sourceKey);
        $content = trim($content);
        if ($sourceKey === '' || $content === '') {
            return ['attachment_id' => 0, 'skipped' => 'empty_input', 'chunk_count' => 0];
        }

        $hash = sha1($content);

        $existing = Attachment::query()
            ->where('user_id', $userId)
            ->where('file_key', $sourceKey)
            ->where('content_hash', $hash)
            ->first();
        if ($existing) {
            return [
                'attachment_id' => (int) $existing->id,
                'skipped' => 'duplicate_hash',
                'chunk_count' => AttachmentChunk::query()->where('attachment_id', $existing->id)->count(),
            ];
        }

        $oldAttachments = Attachment::query()
            ->where('user_id', $userId)
            ->where('file_key', $sourceKey)
            ->get();

        $extByKind = ['doc' => 'md', 'sheet' => 'md', 'bitable' => 'md', 'mail' => 'md', 'chat' => 'md', 'task' => 'md', 'calendar' => 'md', 'meeting' => 'md'];
        $fileExt = $extByKind[$sourceKind] ?? 'md';

        return DB::transaction(function () use ($userId, $sourceKind, $sourceKey, $title, $content, $hash, $mimeType, $fileExt, $oldAttachments) {
            foreach ($oldAttachments as $old) {
                AttachmentChunk::query()->where('attachment_id', $old->id)->delete();
                $old->parse_status = 'superseded';
                $old->save();
            }

            $attachment = Attachment::query()->create([
                'user_id' => $userId,
                'conversation_id' => null,
                'message_id' => null,
                'run_id' => null,
                'source_channel' => 'auto_archive',
                'source_message_id' => null,
                'attachment_type' => 'auto_archive',
                'file_key' => $sourceKey,
                'file_name' => $title !== '' ? $title : ('archive_' . $sourceKey),
                'file_ext' => $fileExt,
                'mime_type' => $mimeType,
                'file_size' => mb_strlen($content),
                'storage_path' => null,
                'content_hash' => $hash,
                'parse_status' => Attachment::STATUS_READY,
                'parse_error' => null,
                'parsed_at' => now(),
                'meta' => [
                    'source_kind' => $sourceKind,
                    'parser' => 'auto_archive',
                ],
            ]);

            $chunks = $this->chunkText($content, 1200, 160);
            $chunks = array_slice($chunks, 0, 100);
            if (empty($chunks)) {
                $chunks = [$content];
            }

            foreach ($chunks as $i => $chunk) {
                $chunk = trim((string) $chunk);
                if ($chunk === '') {
                    continue;
                }
                $keywords = $this->keywords($chunk);
                AttachmentChunk::query()->create([
                    'attachment_id' => $attachment->id,
                    'user_id' => $userId,
                    'run_id' => null,
                    'chunk_index' => $i,
                    'content' => $chunk,
                    'summary' => $this->truncate($chunk, 180),
                    'keywords' => $keywords,
                    'token_estimate' => $this->estimateTokens($chunk),
                    'embedding_source_text' => $chunk,
                    'embedding_vector' => null,
                    'embedding_model' => null,
                    'meta' => ['source_kind' => $sourceKind],
                ]);
            }

            return [
                'attachment_id' => (int) $attachment->id,
                'skipped' => null,
                'chunk_count' => count($chunks),
            ];
        });
    }

    public function prepareKnowledgeContextForRun(Run $run, Collection $messageRows): array
    {
        $user = $run->user;
        if (! $user) {
            return ['prompt' => null, 'hits' => 0, 'attachments' => 0];
        }

        $this->workspaceService->ensure((int) $user->id);

        $query = $this->latestUserText($messageRows);
        $attachmentIds = $this->collectAttachmentIdsFromMessages($messageRows);

        if (! empty($attachmentIds)) {
            $this->parsePendingAttachments((int) $user->id, $attachmentIds, $run);
        }

        $chunks = $this->retrieveChunks((int) $user->id, $query, $attachmentIds);
        if ($chunks->isEmpty()) {
            return ['prompt' => null, 'hits' => 0, 'attachments' => count($attachmentIds)];
        }

        $lines = [];
        foreach ($chunks as $chunk) {
            $fileName = (string) ($chunk->attachment_file_name ?: ('attachment#'.$chunk->attachment_id));
            $lines[] = '- ['.$fileName.' | chunk '.((int) $chunk->chunk_index).'] '.$this->truncate((string) $chunk->content, 1500);
        }

        $prompt = "【用户上传文件知识库检索结果】\n"
            ."以下内容来自该用户上传并解析后的文件，请优先基于这些材料回答，并在答案中引用文件名：\n"
            .implode("\n", $lines);

        return [
            'prompt' => $prompt,
            'hits' => $chunks->count(),
            'attachments' => count($attachmentIds),
        ];
    }

    public function getUserKnowledgeOverview(int $userId): array
    {
        $attachments = Attachment::query()->where('user_id', $userId);
        $chunks = AttachmentChunk::query()->where('user_id', $userId);

        return [
            'attachments_total' => (int) $attachments->count(),
            'attachments_ready' => (int) (clone $attachments)->where('parse_status', Attachment::STATUS_READY)->count(),
            'attachments_failed' => (int) (clone $attachments)->where('parse_status', Attachment::STATUS_FAILED)->count(),
            'chunk_total' => (int) $chunks->count(),
            'recent_attachments' => Attachment::query()
                ->where('user_id', $userId)
                ->latest('id')
                ->limit(20)
                ->get(),
        ];
    }

    private function parsePendingAttachments(int $userId, array $attachmentIds, Run $run): void
    {
        $rows = Attachment::query()
            ->where('user_id', $userId)
            ->whereIn('id', $attachmentIds)
            ->whereIn('parse_status', [Attachment::STATUS_QUEUED, Attachment::STATUS_FAILED])
            ->orderBy('id')
            ->limit(5)
            ->get();

        foreach ($rows as $row) {
            try {
                $this->parseAttachment($row, $run);
            } catch (\Throwable $e) {
                $row->parse_status = Attachment::STATUS_FAILED;
                $row->parse_error = $this->truncate($e->getMessage(), 500);
                $row->save();

                Log::warning('attachment.parse.failed', [
                    'attachment_id' => $row->id,
                    'user_id' => $userId,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    private function parseAttachment(Attachment $attachment, Run $run): void
    {
        $attachment->parse_status = Attachment::STATUS_DOWNLOADING;
        $attachment->parse_error = null;
        $attachment->save();

        if (! is_string($attachment->storage_path) || trim($attachment->storage_path) === '' || ! File::exists($attachment->storage_path)) {
            $download = $this->downloadAttachment($attachment);
            $attachment->storage_path = $download['path'];
            $attachment->mime_type = $attachment->mime_type ?: ($download['mime_type'] ?: null);
            $attachment->file_size = $attachment->file_size ?: ($download['size'] ?: null);
            $attachment->file_name = $attachment->file_name ?: ($download['file_name'] ?: null);
            $attachment->file_ext = $attachment->file_ext ?: ($download['file_ext'] ?: null);
            $attachment->content_hash = $download['sha1'] ?: null;
            $attachment->save();
        }

        $attachment->parse_status = Attachment::STATUS_PARSING;
        if (! $attachment->run_id) {
            $attachment->run_id = $run->id;
        }
        $attachment->save();

        $parsed = $this->extractText($attachment);
        $text = trim((string) ($parsed['text'] ?? ''));
        $meta = (array) ($parsed['meta'] ?? []);

        DB::transaction(function () use ($attachment, $run, $text, $meta): void {
            AttachmentChunk::query()->where('attachment_id', $attachment->id)->delete();

            $chunks = $this->chunkText($text, 1200, 160);
            if (empty($chunks)) {
                $chunks = [$text !== '' ? $text : '该文件已保存，但暂未提取到可检索文本。'];
            }

            $knowledgeJsonl = $this->knowledgeChunkFilePath((int) $attachment->user_id, (int) $attachment->id);
            File::put($knowledgeJsonl, '');

            foreach ($chunks as $i => $chunk) {
                $chunk = trim((string) $chunk);
                if ($chunk === '') {
                    continue;
                }

                $keywords = $this->keywords($chunk);
                AttachmentChunk::query()->create([
                    'attachment_id' => $attachment->id,
                    'user_id' => $attachment->user_id,
                    'run_id' => $run->id,
                    'chunk_index' => $i,
                    'content' => $chunk,
                    'summary' => $this->truncate($chunk, 180),
                    'keywords' => $keywords,
                    'token_estimate' => $this->estimateTokens($chunk),
                    'embedding_source_text' => $chunk,
                    'embedding_vector' => null,
                    'embedding_model' => null,
                    'meta' => $meta,
                ]);

                File::append($knowledgeJsonl, json_encode([
                    'chunk_index' => $i,
                    'content' => $chunk,
                    'keywords' => $keywords,
                ], JSON_UNESCAPED_UNICODE).PHP_EOL);
            }

            $attachmentMeta = is_array($attachment->meta) ? $attachment->meta : [];
            $attachmentMeta['parser'] = (string) ($meta['parser'] ?? 'unknown');
            $attachmentMeta['knowledge_file'] = $this->relativeStoragePath($knowledgeJsonl);

            $attachment->meta = $attachmentMeta;
            $attachment->parse_status = Attachment::STATUS_READY;
            $attachment->parsed_at = now();
            $attachment->run_id = $attachment->run_id ?: $run->id;
            $attachment->save();
        });
    }

    private function downloadAttachment(Attachment $attachment): array
    {
        $paths = $this->workspaceService->ensure((int) $attachment->user_id);
        $dateDir = $paths['uploads_raw'].'/'.now()->format('Ymd');
        if (! File::isDirectory($dateDir)) {
            File::makeDirectory($dateDir, 0755, true);
        }

        $safeName = $this->normalizeFileName((string) ($attachment->file_name ?: $attachment->file_key ?: 'attachment.bin'));
        $targetPath = $dateDir.'/'.$attachment->id.'_'.$safeName;

        $result = $this->feishuService->downloadMessageResource([
            'source_message_id' => (string) ($attachment->source_message_id ?? ''),
            'file_key' => (string) ($attachment->file_key ?? ''),
            'attachment_type' => (string) ($attachment->attachment_type ?? 'file'),
            'target_path' => $targetPath,
        ]);

        return [
            'path' => (string) ($result['path'] ?? $targetPath),
            'mime_type' => trim((string) ($result['mime_type'] ?? '')),
            'size' => $this->toIntOrNull($result['size'] ?? null),
            'file_name' => $this->normalizeFileName((string) ($result['file_name'] ?? basename($targetPath))),
            'file_ext' => strtolower(pathinfo((string) ($result['file_name'] ?? basename($targetPath)), PATHINFO_EXTENSION)),
            'sha1' => File::exists((string) ($result['path'] ?? $targetPath))
                ? sha1((string) File::get((string) ($result['path'] ?? $targetPath)))
                : null,
        ];
    }

    private function extractText(Attachment $attachment): array
    {
        $path = (string) ($attachment->storage_path ?? '');
        if ($path === '' || ! File::exists($path)) {
            return [
                'text' => '',
                'meta' => ['parser' => 'missing_file'],
            ];
        }

        $ext = strtolower(trim((string) ($attachment->file_ext ?: pathinfo($path, PATHINFO_EXTENSION))));
        $mime = strtolower(trim((string) ($attachment->mime_type ?? '')));
        $type = strtolower(trim((string) ($attachment->attachment_type ?? 'file')));

        if ($type === 'image' || str_starts_with($mime, 'image/')) {
            return $this->extractImageTextWithVisionModel($path, $mime);
        }

        if (in_array($ext, ['txt', 'md', 'csv', 'json', 'xml', 'html', 'htm', 'log'], true)) {
            $text = (string) File::get($path);

            return ['text' => $this->truncate($text, 200000), 'meta' => ['parser' => 'plain_text']];
        }

        if ($ext === 'docx') {
            return ['text' => $this->extractDocxText($path), 'meta' => ['parser' => 'docx']];
        }

        if ($ext === 'pptx') {
            return ['text' => $this->extractPptxText($path), 'meta' => ['parser' => 'pptx']];
        }

        if ($ext === 'xlsx') {
            return ['text' => $this->extractXlsxText($path), 'meta' => ['parser' => 'xlsx']];
        }

        if (in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'mp3', 'wav', 'm4a'], true) || in_array($type, ['video', 'audio'], true)) {
            return [
                'text' => '该多媒体文件已接收，当前版本仅建立元数据索引（文件名/类型/大小），后续将接入转写与关键帧识别。',
                'meta' => ['parser' => 'media_metadata_only'],
            ];
        }

        if ($ext === 'pdf') {
            return ['text' => $this->extractPdfText($path), 'meta' => ['parser' => 'smalot_pdfparser']];
        }

        return [
            'text' => '该附件已接收，但当前类型暂未支持自动文本抽取。已完成文件落地与索引占位。',
            'meta' => ['parser' => 'fallback'],
        ];
    }

private function extractPdfText(string $path): string
    {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($path);
            $text = trim((string) $pdf->getText());
            if ($text === '') {
                return '该 PDF 已接收，但未抽取出文本（可能是扫描件/图片型 PDF）。';
            }
            return $this->truncate($text, 200000);
        } catch (\Throwable $e) {
            return '该 PDF 解析失败：' . $e->getMessage();
        }
    }

    /**
     * R3e (丁方案): vision 调用前先 check 是否有视觉能力，没有就直接 graceful degrade，
     * 避免拿不支持 vision 的文本模型瞎调（既浪费 token 又拿不到有效响应）。
     *
     * 检查顺序：
     *   1. active_vision_model_id 非空 → 走它（视觉覆盖优先）
     *   2. active_vision_model_id 为空 → 看 active_main_model_id 对应的 model entry
     *      capabilities 是否含 'vision' → 含则用主模型（多模态主模型情况）
     *   3. 都没 → 直接返 fallback 文本，明确告诉用户"未配置视觉能力"
     */
    private function extractImageTextWithVisionModel(string $path, string $mime): array
    {
        if (! $this->hasVisionCapability()) {
            return [
                'text' => '图片已接收。当前未配置视觉模型，且主模型不支持读图——无法提取图片中的文字或要点。'
                    .'请管理员前往「系统配置 → 模型配置」，为主模型挂载视觉能力，或单独配置一个视觉覆盖模型。',
                'meta' => ['parser' => 'vision_unavailable'],
            ];
        }

        $mime = $mime !== '' ? $mime : (mime_content_type($path) ?: 'image/png');
        $data = base64_encode((string) File::get($path));
        $dataUrl = 'data:'.$mime.';base64,'.$data;

        $messages = [
            [
                'role' => 'system',
                'content' => '你是企业文件解析助手。请提取图片中的文字、表格要点、关键数字，并给出结构化摘要。',
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => '请识别这张图片，并输出可检索文本。'],
                    ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                ],
            ],
        ];

        try {
            $resp = $this->llmGatewayService->chatWithCapability($messages, 'vision');
            $text = trim((string) ($resp['content'] ?? ''));
            if ($text !== '') {
                return [
                    'text' => $this->truncate($text, 120000),
                    'meta' => ['parser' => 'vision_model', 'model' => (string) ($resp['model'] ?? '')],
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('attachment.vision.parse_exception', ['message' => $e->getMessage()]);
        }

        return [
            'text' => '图片已接收，但视觉模型调用失败，暂未提取到可检索文本。',
            'meta' => ['parser' => 'vision_fallback'],
        ];
    }

    /**
     * R3e: 判断当前系统是否有任何可用的视觉能力。
     * - 优先级 1: active_vision_model_id 非空 → 有
     * - 优先级 2: active_main_model_id 对应的 model entry 在某个 active provider 的 models[] 里
     *           且 capabilities 含 'vision' → 有
     * - 否则没
     */
    private function hasVisionCapability(): bool
    {
        $visionId = trim((string) \App\Models\Setting::read('active_vision_model_id', ''));
        if ($visionId !== '') {
            return true;
        }

        $mainId = trim((string) \App\Models\Setting::read('active_main_model_id', ''));
        if ($mainId === '') {
            return false;
        }

        $providers = \App\Models\ModelProvider::query()
            ->where('is_active', true)
            ->get();
        foreach ($providers as $p) {
            $models = is_array($p->models) ? $p->models : [];
            foreach ($models as $m) {
                if (trim((string) ($m['model_id'] ?? '')) !== $mainId) {
                    continue;
                }
                $caps = isset($m['capabilities']) && is_array($m['capabilities'])
                    ? $m['capabilities']
                    : [strtolower(trim((string) ($m['capability'] ?? '')))];
                $caps = array_map(fn ($c) => strtolower(trim((string) $c)), $caps);
                if (in_array('vision', $caps, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function extractDocxText(string $path): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }

        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === '') {
            return '';
        }

        return $this->normalizeXmlText($xml);
    }

    private function extractPptxText(string $path): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }

        $all = [];
        for ($i = 1; $i <= 200; $i++) {
            $xml = (string) $zip->getFromName('ppt/slides/slide'.$i.'.xml');
            if ($xml === '') {
                continue;
            }
            $all[] = $this->normalizeXmlText($xml);
        }
        $zip->close();

        return trim(implode("\n\n", $all));
    }

    private function extractXlsxText(string $path): string
    {
        // R-attach: 用 phpoffice/phpspreadsheet 真解析（之前只读 sharedStrings.xml，
        // 这只能拿到字符串单元格，所有数字 cell（费用、人天、金额等）全部丢失）。
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);  // 不加载样式/计算公式，省内存
            $spreadsheet = $reader->load($path);

            $lines = [];
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $sheetTitle = trim((string) $sheet->getTitle());
                if ($sheetTitle !== '') {
                    $lines[] = '【Sheet: ' . $sheetTitle . '】';
                }

                foreach ($sheet->toArray(null, true, true, false) as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $cells = [];
                    foreach ($row as $cell) {
                        if ($cell === null) {
                            $cells[] = '';
                            continue;
                        }
                        $val = is_scalar($cell) ? (string) $cell : '';
                        $cells[] = trim($val);
                    }
                    // 跳过整行全空的行
                    if (implode('', $cells) === '') {
                        continue;
                    }
                    // 用 ' | ' 分隔 cell，让 LLM 看到列与列的对应关系
                    $lines[] = implode(' | ', $cells);
                }
                $lines[] = '';
            }

            $text = trim(implode("\n", $lines));
            if ($text !== '') {
                return $text;
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('attachment.xlsx_phpspreadsheet_failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            // fall through to legacy sharedStrings parse
        }

        // 兜底：phpspreadsheet 失败（坏文件/格式异常）时回退到老的 sharedStrings 提取
        // 这至少能拿到字符串内容，比完全失败好
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }

        $shared = (string) $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();

        if ($shared === '') {
            return '';
        }

        return $this->normalizeXmlText($shared);
    }

    private function normalizeXmlText(string $xml): string
    {
        $xml = preg_replace('/<[^>]+>/', "\n", $xml);
        $xml = html_entity_decode((string) $xml, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $xml = preg_replace('/\n{2,}/', "\n", (string) $xml);

        return trim((string) $xml);
    }

    private function retrieveChunks(int $userId, string $query, array $attachmentIds): Collection
    {
        $keywords = $this->keywords($query);

        // 同一个文件可能被多次上传/解析（用户重发 / 老解析器+新解析器版本共存），
        // 同 file_key 下只保留最新的 attachment.id，避免旧 chunks 污染 prompt。
        if (! empty($attachmentIds)) {
            $latestIds = \App\Models\Attachment::query()
                ->whereIn('id', $attachmentIds)
                ->selectRaw('MAX(id) AS id')
                ->groupBy('file_key')
                ->pluck('id')
                ->all();
            if (! empty($latestIds)) {
                $attachmentIds = array_map('intval', $latestIds);
            }
        }

        $queryBuilder = AttachmentChunk::query()
            ->join('attachments', 'attachment_chunks.attachment_id', '=', 'attachments.id')
            ->where('attachment_chunks.user_id', $userId)
            ->where('attachments.parse_status', Attachment::STATUS_READY);

        if (! empty($attachmentIds)) {
            $queryBuilder->whereIn('attachment_chunks.attachment_id', $attachmentIds);
        }

        $rows = $queryBuilder
            ->select([
                'attachment_chunks.id',
                'attachment_chunks.attachment_id',
                'attachment_chunks.chunk_index',
                'attachment_chunks.content',
                'attachments.file_name as attachment_file_name',
                'attachments.file_ext as attachment_file_ext',
            ])
            ->latest('attachment_chunks.id')
            ->limit(2000)
            ->get();

        if ($rows->count() >= 2000) {
            \Illuminate\Support\Facades\Log::warning('[retrieveChunks] capped', [
                'user_id' => $userId,
                'returned' => $rows->count(),
                'note' => 'single user has >=2000 chunks within retention window; consider raising cap or pruning',
            ]);
        }

        if ($rows->isEmpty()) {
            return collect();
        }

        return $rows->map(function ($row) use ($keywords) {
            $score = 0.05;
            $hay = mb_strtolower((string) $row->content, 'UTF-8');
            foreach ($keywords as $kw) {
                if (mb_strpos($hay, $kw) !== false) {
                    $score += 1.0;
                }
            }
            $row->score = $score;

            return $row;
        })
            ->sortByDesc('score')
            ->take(8)
            ->values();
    }

    private function collectAttachmentIdsFromMessages(Collection $messageRows): array
    {
        $ids = [];

        foreach ($messageRows as $row) {
            if (($row->role ?? '') !== 'user') {
                continue;
            }
            $meta = is_array($row->meta) ? $row->meta : [];
            $attachmentIds = (array) ($meta['attachment_ids'] ?? []);
            foreach ($attachmentIds as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }

        return array_keys($ids);
    }

    private function latestUserText(Collection $messageRows): string
    {
        for ($i = $messageRows->count() - 1; $i >= 0; $i--) {
            $row = $messageRows->get($i);
            if (($row->role ?? '') !== 'user') {
                continue;
            }
            $text = trim((string) ($row->content ?? ''));
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function knowledgeChunkFilePath(int $userId, int $attachmentId): string
    {
        $paths = $this->workspaceService->ensure($userId);

        return $paths['knowledge_chunks'].'/attachment_'.$attachmentId.'.jsonl';
    }

    private function relativeStoragePath(string $absPath): string
    {
        $root = storage_path();
        if (str_starts_with($absPath, $root)) {
            return 'storage/'.ltrim(str_replace('\\', '/', substr($absPath, strlen($root))), '/');
        }

        return $absPath;
    }

    private function chunkText(string $text, int $chunkSize, int $overlap): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        $chunks = [];
        $start = 0;
        $index = 0;

        while ($start < $length && $index < 500) {
            $slice = function_exists('mb_substr')
                ? mb_substr($text, $start, $chunkSize, 'UTF-8')
                : substr($text, $start, $chunkSize);
            $slice = trim((string) $slice);
            if ($slice === '') {
                break;
            }
            $chunks[] = $slice;
            $start += max(1, $chunkSize - $overlap);
            $index++;
        }

        return $chunks;
    }

    private function normalizeFileName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            $name = 'attachment_'.Str::random(8);
        }

        $name = str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|'], '_', $name);

        return $name;
    }

    private function truncate(string $text, int $max): string
    {
        if ($text === '') {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') <= $max) {
                return $text;
            }

            return mb_substr($text, 0, $max - 1, 'UTF-8').'...';
        }
        if (strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max - 3).'...';
    }

    private function keywords(string $text): array
    {
        $parts = preg_split('/[\s,，。！？；;:：、\/\\\\\(\)\[\]\{\}]+/u', mb_strtolower(trim($text), 'UTF-8')) ?: [];

        return collect($parts)
            ->filter(fn ($p) => is_string($p) && mb_strlen(trim($p), 'UTF-8') >= 2)
            ->unique()
            ->take(20)
            ->values()
            ->all();
    }

    private function estimateTokens(string $text): int
    {
        return max(1, (int) ceil(strlen($text) / 4));
    }

    private function toIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
