<?php

namespace Tests\Unit\Modules\Doppelganger;

use App\Models\User;
use App\Modules\Doppelganger\Models\Doppelganger;
use App\Modules\Doppelganger\Models\DoppelgangerGrant;
use App\Modules\Doppelganger\Models\DoppelgangerInvocation;
use App\Modules\Doppelganger\Services\DoppelgangerInvocationService;
use App\Modules\Doppelganger\Services\DoppelgangerReplyFormatter;
use App\Modules\Doppelganger\Services\KnowledgeService;
use App\Modules\Doppelganger\Services\VoiceService;
use App\Modules\Doppelganger\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * 单元测试 DoppelgangerInvocationService 的核心契约：
 *  - parsePrefix 中英冒号都吃；非 ~ 开头返回 null
 *  - resolveDoppelganger 同名歧义、精确/模糊、#ID 显式
 *  - attempt 鉴权（无 grant / 过期 / access_level 不足）
 *  - logInvocation 写 caller_user_id 而非 caller_admin_id
 */
class InvocationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(
        ?KnowledgeService $knowledge = null,
        ?VoiceService $voice = null,
        ?WorkflowService $workflow = null,
    ): DoppelgangerInvocationService {
        return new DoppelgangerInvocationService(
            $knowledge ?? $this->createMock(KnowledgeService::class),
            $voice ?? $this->createMock(VoiceService::class),
            $workflow ?? $this->createMock(WorkflowService::class),
            new DoppelgangerReplyFormatter(),
        );
    }

    // ──────── parsePrefix ────────

    public function test_parsePrefix_recognises_english_colon(): void
    {
        $svc = $this->makeService();
        $this->assertSame(['Andy', '今天怎么样'], $svc->parsePrefix('~Andy: 今天怎么样'));
    }

    public function test_parsePrefix_recognises_chinese_colon(): void
    {
        $svc = $this->makeService();
        $this->assertSame(['陈玉', '羽绒服备货结论'], $svc->parsePrefix('~陈玉：羽绒服备货结论'));
    }

    public function test_parsePrefix_returns_null_for_non_doppelganger_message(): void
    {
        $svc = $this->makeService();
        $this->assertNull($svc->parsePrefix('Hello world'));
        $this->assertNull($svc->parsePrefix('@Andy: 你好'));         // @ 不是 ~
        $this->assertNull($svc->parsePrefix('~ Andy: 缺少名字'));     // ~ 后立刻空白不算
        $this->assertNull($svc->parsePrefix(''));
    }

    public function test_parsePrefix_rejects_overly_long_name(): void
    {
        $svc = $this->makeService();
        $longName = str_repeat('a', 31);
        $this->assertNull($svc->parsePrefix("~{$longName}: payload"));
    }

    public function test_parsePrefix_allows_leading_whitespace(): void
    {
        $svc = $this->makeService();
        $this->assertSame(['Andy', 'q'], $svc->parsePrefix('  ~Andy: q'));
    }

    public function test_parsePrefix_handles_empty_payload(): void
    {
        $svc = $this->makeService();
        $this->assertSame(['Andy', ''], $svc->parsePrefix('~Andy:'));
        $this->assertSame(['Andy', ''], $svc->parsePrefix('~Andy：'));
    }

    // ──────── resolveDoppelganger ────────

    public function test_resolveDoppelganger_finds_active_by_display_name(): void
    {
        $sourceUser = User::factory()->create(['name' => '张三', 'is_active' => false]);
        $dop = Doppelganger::create([
            'source_user_id' => $sourceUser->id,
            'display_name' => 'Andy',
            'status' => Doppelganger::STATUS_ACTIVE,
            'consent_signed_at' => now()->subDay(),
            'expires_at' => now()->addYear(),
        ]);

        $svc = $this->makeService();
        $found = $svc->resolveDoppelganger('Andy');
        $this->assertCount(1, $found);
        $this->assertSame($dop->id, $found->first()->id);
    }

    public function test_resolveDoppelganger_skips_non_active(): void
    {
        $sourceUser = User::factory()->create(['name' => '王五', 'is_active' => false]);
        Doppelganger::create([
            'source_user_id' => $sourceUser->id,
            'display_name' => 'Pending',
            'status' => Doppelganger::STATUS_PENDING,
            'consent_signed_at' => now(),
            'expires_at' => now()->addYear(),
        ]);

        $svc = $this->makeService();
        $this->assertCount(0, $svc->resolveDoppelganger('Pending'));
    }

    public function test_resolveDoppelganger_returns_multiple_for_homonyms(): void
    {
        $u1 = User::factory()->create(['name' => '陈玉', 'is_active' => false]);
        $u2 = User::factory()->create(['name' => '陈玉', 'is_active' => false]);
        Doppelganger::create(['source_user_id' => $u1->id, 'display_name' => '陈玉', 'status' => Doppelganger::STATUS_ACTIVE, 'consent_signed_at' => now(), 'expires_at' => now()->addYear()]);
        Doppelganger::create(['source_user_id' => $u2->id, 'display_name' => '陈玉', 'status' => Doppelganger::STATUS_ACTIVE, 'consent_signed_at' => now(), 'expires_at' => now()->addYear()]);

        $svc = $this->makeService();
        $this->assertCount(2, $svc->resolveDoppelganger('陈玉'));
    }

    public function test_resolveDoppelganger_explicit_hash_id_disambiguates(): void
    {
        $u1 = User::factory()->create(['name' => '陈玉', 'is_active' => false]);
        $u2 = User::factory()->create(['name' => '陈玉', 'is_active' => false]);
        $dop1 = Doppelganger::create(['source_user_id' => $u1->id, 'display_name' => '陈玉', 'status' => Doppelganger::STATUS_ACTIVE, 'consent_signed_at' => now(), 'expires_at' => now()->addYear()]);
        Doppelganger::create(['source_user_id' => $u2->id, 'display_name' => '陈玉', 'status' => Doppelganger::STATUS_ACTIVE, 'consent_signed_at' => now(), 'expires_at' => now()->addYear()]);

        $svc = $this->makeService();
        $found = $svc->resolveDoppelganger("陈玉#{$dop1->id}");
        $this->assertCount(1, $found);
        $this->assertSame($dop1->id, $found->first()->id);
    }

    // ──────── attempt: 鉴权 ────────

    public function test_attempt_returns_null_when_not_doppelganger_message(): void
    {
        $caller = User::factory()->create();
        $svc = $this->makeService();
        $this->assertNull($svc->attempt('hi there', $caller));
    }

    public function test_attempt_rejects_when_no_grant(): void
    {
        $sourceUser = User::factory()->create(['name' => 'Andy', 'is_active' => false]);
        Doppelganger::create([
            'source_user_id' => $sourceUser->id,
            'display_name' => 'Andy',
            'status' => Doppelganger::STATUS_ACTIVE,
            'consent_signed_at' => now(),
            'expires_at' => now()->addYear(),
        ]);
        $caller = User::factory()->create();

        $svc = $this->makeService();
        $reply = $svc->attempt('~Andy：你的项目情况', $caller);
        $this->assertStringContainsString('尚未被授权', $reply);
    }

    public function test_attempt_rejects_when_grant_expired(): void
    {
        $sourceUser = User::factory()->create(['name' => 'Andy', 'is_active' => false]);
        $dop = Doppelganger::create([
            'source_user_id' => $sourceUser->id,
            'display_name' => 'Andy',
            'status' => Doppelganger::STATUS_ACTIVE,
            'consent_signed_at' => now(),
            'expires_at' => now()->addYear(),
        ]);
        $caller = User::factory()->create();
        DoppelgangerGrant::create([
            'doppelganger_id' => $dop->id,
            'grantee_user_id' => $caller->id,
            'access_level' => DoppelgangerGrant::ACCESS_FULL,
            'expires_at' => now()->subDay(),  // 已过期
        ]);

        $svc = $this->makeService();
        $reply = $svc->attempt('~Andy：项目情况', $caller);
        $this->assertStringContainsString('尚未被授权', $reply);
    }

    public function test_attempt_rejects_write_when_only_read_only(): void
    {
        $sourceUser = User::factory()->create(['name' => 'Andy', 'is_active' => false]);
        $dop = Doppelganger::create([
            'source_user_id' => $sourceUser->id,
            'display_name' => 'Andy',
            'status' => Doppelganger::STATUS_ACTIVE,
            'consent_signed_at' => now(),
            'expires_at' => now()->addYear(),
        ]);
        $caller = User::factory()->create();
        DoppelgangerGrant::create([
            'doppelganger_id' => $dop->id,
            'grantee_user_id' => $caller->id,
            'access_level' => DoppelgangerGrant::ACCESS_READ_ONLY,
            'expires_at' => now()->addYear(),
        ]);

        $svc = $this->makeService();
        $reply = $svc->attempt('~Andy：write 给客户的邮件', $caller);
        $this->assertStringContainsString('不允许', $reply);
        $this->assertStringContainsString('write', $reply);
    }

    public function test_attempt_writing_is_not_a_subcommand_falls_to_ask(): void
    {
        // 'writing' is NOT 'write' (no trailing whitespace) → should be Level 1 ask, not Level 2 draft
        $sourceUser = User::factory()->create(['name' => 'Andy', 'is_active' => false]);
        $dop = Doppelganger::create([
            'source_user_id' => $sourceUser->id,
            'display_name' => 'Andy',
            'status' => Doppelganger::STATUS_ACTIVE,
            'consent_signed_at' => now(),
            'expires_at' => now()->addYear(),
        ]);
        $caller = User::factory()->create();
        DoppelgangerGrant::create([
            'doppelganger_id' => $dop->id,
            'grantee_user_id' => $caller->id,
            'access_level' => DoppelgangerGrant::ACCESS_READ_ONLY,  // 只读
            'expires_at' => now()->addYear(),
        ]);

        $knowledge = $this->createMock(KnowledgeService::class);
        $knowledge->method('ask')->willReturn(['answer' => 'ok', 'sources' => [], 'token_input' => 0, 'token_output' => 0]);
        $svc = $this->makeService(knowledge: $knowledge);
        $reply = $svc->attempt('~Andy：writing letter', $caller);

        // 不应该被当作 write 子命令拒绝（read_only 也能跑 ask）
        $this->assertStringNotContainsString('不允许', $reply);
    }

    public function test_attempt_run_alone_lists_workflows(): void
    {
        $sourceUser = User::factory()->create(['name' => 'Andy', 'is_active' => false]);
        $dop = Doppelganger::create([
            'source_user_id' => $sourceUser->id,
            'display_name' => 'Andy',
            'status' => Doppelganger::STATUS_ACTIVE,
            'consent_signed_at' => now(),
            'expires_at' => now()->addYear(),
        ]);
        $caller = User::factory()->create();
        DoppelgangerGrant::create([
            'doppelganger_id' => $dop->id,
            'grantee_user_id' => $caller->id,
            'access_level' => DoppelgangerGrant::ACCESS_FULL,
            'expires_at' => now()->addYear(),
        ]);

        $workflow = $this->createMock(WorkflowService::class);
        $workflow->method('listForDoppelganger')->willReturn(collect([]));
        $svc = $this->makeService(workflow: $workflow);

        $reply = $svc->attempt('~Andy：run', $caller);
        $this->assertStringContainsString('没有任何可用工作流', $reply);
    }

    public function test_attempt_logs_caller_user_id_not_admin_id(): void
    {
        $sourceUser = User::factory()->create(['name' => 'Andy', 'is_active' => false]);
        $dop = Doppelganger::create([
            'source_user_id' => $sourceUser->id,
            'display_name' => 'Andy',
            'status' => Doppelganger::STATUS_ACTIVE,
            'consent_signed_at' => now(),
            'expires_at' => now()->addYear(),
        ]);
        $caller = User::factory()->create();
        DoppelgangerGrant::create([
            'doppelganger_id' => $dop->id,
            'grantee_user_id' => $caller->id,
            'access_level' => DoppelgangerGrant::ACCESS_FULL,
            'expires_at' => now()->addYear(),
        ]);

        // mock KnowledgeService to avoid LLM call
        $knowledge = $this->createMock(KnowledgeService::class);
        $knowledge->method('ask')->willReturn([
            'answer' => '测试回答',
            'sources' => [],
            'token_input' => 10,
            'token_output' => 5,
        ]);

        $svc = $this->makeService(knowledge: $knowledge);
        $svc->attempt('~Andy：测试问题', $caller);

        $inv = DoppelgangerInvocation::query()->latest('id')->first();
        $this->assertNotNull($inv);
        $this->assertSame($caller->id, $inv->caller_user_id);
        $this->assertNull($inv->caller_admin_id);
        $this->assertSame(DoppelgangerInvocation::LEVEL_KNOWLEDGE, $inv->level);
    }

    public function test_attempt_returns_helpful_error_for_empty_payload(): void
    {
        $caller = User::factory()->create();
        $svc = $this->makeService();
        $reply = $svc->attempt('~Andy：', $caller);
        $this->assertStringContainsString('请在', $reply);
        $this->assertStringContainsString('Andy', $reply);
    }

    public function test_attempt_returns_helpful_error_when_doppelganger_not_found(): void
    {
        $caller = User::factory()->create();
        $svc = $this->makeService();
        $reply = $svc->attempt('~不存在的人：你好', $caller);
        $this->assertStringContainsString('未找到', $reply);
    }
}
