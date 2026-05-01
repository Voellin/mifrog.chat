<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Models\AttachmentChunk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Periodic cleanup for auto_archive attachments older than 90 days.
 *
 * ActivityArchiveKernel 每 2 小时把用户飞书侧浏览/编辑过的文档 fetch 一次落到
 * attachments + attachment_chunks (attachment_type='auto_archive')。90 天后
 * 清掉避免无限膨胀。用户主动上传的 attachments（type='file'）不动。
 *
 * 周期为何放宽到 90 天：配合 AttachmentService::retrieveChunks 去掉 limit(120)
 * 的检索限制，让用户能召回 3 个月内看过的飞书 doc/sheet/ppt/邮件归档。
 */
class AttachmentCleanupArchiveCommand extends Command
{
    protected $signature = 'attachment:cleanup-archive
        {--days=90 : Delete auto_archive attachments older than N days}
        {--dry-run : Print what would be deleted without actually deleting}';

    protected $description = 'Cleanup auto-archived feishu document attachments older than N days';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $query = Attachment::query()
            ->where('attachment_type', 'auto_archive')
            ->where('created_at', '<', $cutoff);

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info("No auto_archive attachments older than {$days} days.");
            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Found {$total} auto_archive attachments older than {$days} days.");

        if ($dryRun) {
            return self::SUCCESS;
        }

        $deletedAttachments = 0;
        $deletedChunks = 0;

        (clone $query)->chunkById(200, function ($attachments) use (&$deletedAttachments, &$deletedChunks) {
            foreach ($attachments as $attachment) {
                $chunkCount = AttachmentChunk::query()->where('attachment_id', $attachment->id)->count();
                AttachmentChunk::query()->where('attachment_id', $attachment->id)->delete();
                $deletedChunks += $chunkCount;

                $attachment->delete();
                $deletedAttachments++;
            }
        });

        Log::info('[AttachmentCleanupArchive] done', [
            'days' => $days,
            'deleted_attachments' => $deletedAttachments,
            'deleted_chunks' => $deletedChunks,
        ]);

        $this->info("Deleted {$deletedAttachments} attachments + {$deletedChunks} chunks.");
        return self::SUCCESS;
    }
}
