<?php

namespace App\Modules\Doppelganger\Services;

use App\Models\AdminUser;
use App\Models\User;
use App\Modules\Doppelganger\Models\Doppelganger;
use App\Modules\Doppelganger\Models\DoppelgangerGrant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Doppelganger 主服务：CRUD + 状态机 + 授权管理
 *
 * 状态流转：
 *   pending → sample_extracting → active → (paused | expired | revoked)
 *   active 可手动 paused，也可自动 expired（到期）
 *   revoked 是终态（员工撤销同意 / admin 强制下线）
 */
class DoppelgangerService
{
    public function __construct(
        private readonly SampleExtractorService $sampleExtractor,
    ) {}

    /**
     * 创建数字分身（pending 状态，等管理员 activate）
     */
    public function create(int $sourceUserId, array $attrs): Doppelganger
    {
        $user = User::findOrFail($sourceUserId);

        // 唯一性检查
        $existing = Doppelganger::where('source_user_id', $sourceUserId)->first();
        if ($existing) {
            throw new \DomainException("用户 #{$sourceUserId} 已存在数字分身（id={$existing->id}）");
        }

        return Doppelganger::create([
            'source_user_id' => $sourceUserId,
            'display_name' => $attrs['display_name'] ?? ($user->name . ' 的数字分身'),
            'status' => Doppelganger::STATUS_PENDING,
            'consent_signed_at' => $attrs['consent_signed_at'] ?? null,
            'consent_doc_path' => $attrs['consent_doc_path'] ?? null,
            'expires_at' => $attrs['expires_at'] ?? null,
            'meta' => $attrs['meta'] ?? [],
        ]);
    }

    /**
     * 激活分身 → 触发样本提取（同步触发，建议在后台 queue 中跑）
     */
    public function activate(Doppelganger $dop, AdminUser $by): Doppelganger
    {
        if ($dop->status === Doppelganger::STATUS_ACTIVE) {
            return $dop;
        }
        if (in_array($dop->status, [Doppelganger::STATUS_REVOKED, Doppelganger::STATUS_EXPIRED], true)) {
            throw new \DomainException("已 {$dop->status} 的分身不可激活");
        }
        if (! $dop->consent_signed_at) {
            throw new \DomainException("缺少员工签字同意书，不可激活");
        }
        if (! $dop->expires_at) {
            throw new \DomainException("必须设置到期时间");
        }

        DB::transaction(function () use ($dop) {
            $dop->update([
                'status' => Doppelganger::STATUS_SAMPLE_EXTRACTING,
                'enabled_at' => now(),
            ]);
        });

        Log::info('[Doppelganger] activated, kicking sample extraction', [
            'doppelganger_id' => $dop->id,
            'source_user_id' => $dop->source_user_id,
            'admin_id' => $by->id,
        ]);

        // 触发样本提取（同步 / 数据量小时即可；大量数据时改 dispatch queue）
        try {
            $this->sampleExtractor->extractAll($dop);
            $dop->update([
                'status' => Doppelganger::STATUS_ACTIVE,
                'samples_extracted_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[Doppelganger] sample extraction failed', [
                'doppelganger_id' => $dop->id,
                'error' => $e->getMessage(),
            ]);
            $dop->update(['status' => Doppelganger::STATUS_PENDING]);
            throw $e;
        }

        return $dop->fresh();
    }

    public function pause(Doppelganger $dop): void
    {
        if ($dop->status !== Doppelganger::STATUS_ACTIVE) {
            throw new \DomainException("只能暂停 active 状态的分身");
        }
        $dop->update(['status' => Doppelganger::STATUS_PAUSED]);
    }

    public function resume(Doppelganger $dop): void
    {
        if ($dop->status !== Doppelganger::STATUS_PAUSED) {
            throw new \DomainException("只能恢复 paused 状态的分身");
        }
        if ($dop->isExpired()) {
            throw new \DomainException("已过期，请先续期");
        }
        $dop->update(['status' => Doppelganger::STATUS_ACTIVE]);
    }

    public function revoke(Doppelganger $dop, string $reason = ''): void
    {
        $dop->update([
            'status' => Doppelganger::STATUS_REVOKED,
            'meta' => array_merge((array) $dop->meta, [
                'revoked_at' => now()->toIso8601String(),
                'revoked_reason' => $reason,
            ]),
        ]);
    }

    /**
     * 续费：延长 expires_at + 记录 service_fee_paid_until
     */
    public function extend(Doppelganger $dop, int $months, ?string $note = null): void
    {
        $newExpiry = $dop->expires_at && $dop->expires_at->isFuture()
            ? $dop->expires_at->copy()->addMonths($months)
            : now()->addMonths($months);

        $dop->update([
            'expires_at' => $newExpiry,
            'service_fee_paid_until' => $newExpiry,
            'status' => $dop->status === Doppelganger::STATUS_EXPIRED
                ? Doppelganger::STATUS_ACTIVE
                : $dop->status,
            'meta' => array_merge((array) $dop->meta, [
                'last_extended_at' => now()->toIso8601String(),
                'last_extended_months' => $months,
                'last_extended_note' => $note,
            ]),
        ]);
    }

    /**
     * 给某接班人授权
     */
    public function grant(
        Doppelganger $dop,
        int $granteeUserId,
        string $accessLevel,
        AdminUser $by,
        ?\DateTimeInterface $expiresAt = null
    ): DoppelgangerGrant {
        if (! in_array($accessLevel, DoppelgangerGrant::ACCESS_LEVELS, true)) {
            throw new \InvalidArgumentException("无效的 access_level: {$accessLevel}");
        }
        return DoppelgangerGrant::updateOrCreate(
            ['doppelganger_id' => $dop->id, 'grantee_user_id' => $granteeUserId],
            [
                'access_level' => $accessLevel,
                'granted_by_admin_id' => $by->id,
                'expires_at' => $expiresAt,
            ]
        );
    }

    public function revokeGrant(int $grantId): void
    {
        DoppelgangerGrant::findOrFail($grantId)->delete();
    }

    /**
     * 检查某 user 是否能调用该分身（Level 1/2/3 各自的权限）
     */
    public function canInvoke(Doppelganger $dop, int $callerUserId, int $level): bool
    {
        if (! $dop->isActive()) return false;

        $grant = DoppelgangerGrant::where('doppelganger_id', $dop->id)
            ->where('grantee_user_id', $callerUserId)
            ->first();
        if (! $grant || ! $grant->isActive()) return false;

        return match ($level) {
            1 => true, // 任何 grant 都能 read
            2 => $grant->canUseVoice(),
            3 => $grant->canUseWorkflow(),
            default => false,
        };
    }

    /**
     * 周期检查到期分身：到期自动 expire（cron 调用）
     */
    public function tickExpire(): int
    {
        $count = 0;
        Doppelganger::where('status', Doppelganger::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->chunkById(100, function ($batch) use (&$count) {
                foreach ($batch as $dop) {
                    $dop->update(['status' => Doppelganger::STATUS_EXPIRED]);
                    $count++;
                    Log::info('[Doppelganger] auto-expired', ['doppelganger_id' => $dop->id]);
                }
            });
        return $count;
    }
}
