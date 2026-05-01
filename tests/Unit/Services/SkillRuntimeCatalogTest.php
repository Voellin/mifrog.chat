<?php

namespace Tests\Unit\Services;

use App\Models\Skill;
use App\Models\User;
use App\Services\LlmGatewayService;
use App\Services\SkillApiExecutorService;
use App\Services\SkillRuntimeService;
use App\Services\SkillSandboxService;
use App\Services\SkillStorageService;
use DomainException;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class SkillRuntimeCatalogTest extends TestCase
{
    private function makeSkill(array $attrs): Skill
    {
        $skill = new Skill();
        $skill->forceFill($attrs);
        $skill->exists = true;
        return $skill;
    }

    private function makeService(Collection $allowed, ?Skill $readsBody = null, string $md = ''): SkillRuntimeService
    {
        $storage = $this->createMock(SkillStorageService::class);
        $storage->method('allowedSkillsForUser')->willReturn($allowed);
        if ($readsBody !== null) {
            $storage->method('readSkillMarkdown')->willReturn($md);
        }
        $sandbox = $this->createMock(SkillSandboxService::class);
        $llm = $this->createMock(LlmGatewayService::class);
        $api = $this->createMock(SkillApiExecutorService::class);
        return new SkillRuntimeService($storage, $sandbox, $llm, $api);
    }

    public function testBuildSkillCatalogEmptyWhenNoAllowedSkills(): void
    {
        $service = $this->makeService(collect());
        $user = new User();
        $user->id = 1;
        $this->assertSame([], $service->buildSkillCatalog($user));
    }

    public function testBuildSkillCatalogShapesEntry(): void
    {
        $s = $this->makeSkill([
            'id' => 42,
            'skill_key' => 'calc',
            'name' => 'Calc',
            'description' => 'Simple calculator',
            'meta' => ['task_kinds' => ['math'], 'executor' => 'sandbox'],
            'is_active' => 1,
        ]);
        $service = $this->makeService(collect([$s]));
        $user = new User();
        $user->id = 1;

        $catalog = $service->buildSkillCatalog($user);
        $this->assertCount(1, $catalog);
        $this->assertSame('calc', $catalog[0]['skill_key']);
        $this->assertSame('Calc', $catalog[0]['name']);
        $this->assertSame('Simple calculator', $catalog[0]['description']);
        $this->assertSame(['math'], $catalog[0]['task_kinds']);
        $this->assertSame('sandbox', $catalog[0]['executor']);
    }

    public function testBuildSkillCatalogDefaultsExecutorToLlm(): void
    {
        $s = $this->makeSkill([
            'id' => 43,
            'skill_key' => 'story',
            'name' => 'Story',
            'description' => '',
            'meta' => [],
            'is_active' => 1,
        ]);
        $service = $this->makeService(collect([$s]));
        $user = new User();
        $user->id = 1;

        $catalog = $service->buildSkillCatalog($user);
        $this->assertSame('llm', $catalog[0]['executor']);
    }

    public function testBuildSkillCatalogIncludesApiParamsForHttpApiExecutor(): void
    {
        $s = $this->makeSkill([
            'id' => 44,
            'skill_key' => 'inventory',
            'name' => '库存查询',
            'description' => '查商品库存',
            'meta' => [
                'executor' => 'http_api',
                'task_kinds' => ['internal_api_call'],
                'api_params' => [
                    ['name' => '商品ID', 'api_key' => 'spu_id', 'description' => '', 'required' => true],
                ],
            ],
            'is_active' => 1,
        ]);
        $service = $this->makeService(collect([$s]));
        $user = new User();
        $user->id = 1;

        $catalog = $service->buildSkillCatalog($user);
        $this->assertSame('http_api', $catalog[0]['executor']);
        $this->assertIsArray($catalog[0]['api_params'] ?? null);
        $this->assertSame('spu_id', $catalog[0]['api_params'][0]['api_key']);
        $this->assertSame('商品ID', $catalog[0]['api_params'][0]['name']);
        $this->assertTrue((bool) $catalog[0]['api_params'][0]['required']);
    }

    public function testLoadSkillBodyReturnsBody(): void
    {
        $s = $this->makeSkill([
            'id' => 10,
            'skill_key' => 'calc',
            'name' => 'Calc',
            'description' => '',
            'meta' => [],
            'is_active' => 1,
        ]);
        $service = $this->makeService(collect([$s]), $s, "# Calc\nAdd numbers");
        $user = new User();
        $user->id = 1;

        $body = $service->loadSkillBody($user, 'calc');
        $this->assertStringContainsString('Calc', $body);
    }

    public function testLoadSkillBodyThrowsWhenNotAllowed(): void
    {
        $service = $this->makeService(collect());
        $user = new User();
        $user->id = 1;

        $this->expectException(DomainException::class);
        $service->loadSkillBody($user, 'nonexistent');
    }

    public function testLoadSkillBodyRejectsEmptyKey(): void
    {
        $service = $this->makeService(collect());
        $user = new User();
        $user->id = 1;

        $this->expectException(DomainException::class);
        $service->loadSkillBody($user, '');
    }

    public function testExecuteSandboxByKeyThrowsForNonSandboxSkill(): void
    {
        $s = $this->makeSkill([
            'id' => 11,
            'skill_key' => 'story',
            'name' => 'Story',
            'description' => '',
            'meta' => ['executor' => 'llm'],
            'is_active' => 1,
        ]);
        $service = $this->makeService(collect([$s]));
        $user = new User();
        $user->id = 1;

        $this->expectException(DomainException::class);
        $service->executeSandboxByKey($user, 'story', 'do something');
    }

    public function testExecuteApiByKeyThrowsForNonHttpApiSkill(): void
    {
        $s = $this->makeSkill([
            'id' => 12,
            'skill_key' => 'story',
            'name' => 'Story',
            'description' => '',
            'meta' => ['executor' => 'llm'],
            'is_active' => 1,
        ]);
        $service = $this->makeService(collect([$s]));
        $user = new User();
        $user->id = 1;

        $this->expectException(DomainException::class);
        $service->executeApiByKey($user, 'story', '', 'run-1');
    }
}
