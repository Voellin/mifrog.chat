<?php

namespace Tests\Unit\Services\Prompt\Sections;

use App\Services\Prompt\Sections\SkillsSection;
use PHPUnit\Framework\TestCase;

class SkillsSectionHttpApiTest extends TestCase
{
    public function testHttpApiEntryRendersApiTag(): void
    {
        $section = new SkillsSection();
        $out = $section->render([
            'skill_catalog' => [
                [
                    'skill_key' => 'inventory',
                    'name' => '库存查询',
                    'description' => '根据商品查仓库库存',
                    'task_kinds' => ['internal_api_call'],
                    'executor' => 'http_api',
                    'api_params' => [
                        ['name' => '商品ID', 'api_key' => 'spu_id', 'description' => '商品唯一标识', 'required' => true],
                        ['name' => '仓库编号', 'api_key' => 'warehouse', 'description' => '', 'required' => false],
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString('- /inventory', $out);
        $this->assertStringContainsString('[api]', $out);
        $this->assertStringContainsString('库存查询', $out);
        $this->assertStringContainsString('· 商品ID (必填)', $out);
        $this->assertStringContainsString('· 仓库编号 (可选)', $out);
        // Should not mislabel http_api as instruction or sandbox
        $this->assertStringNotContainsString('/inventory — 库存查询 [instruction]', $out);
        $this->assertStringNotContainsString('/inventory — 库存查询 [sandbox]', $out);
    }

    public function testHttpApiUsageInstructionsIncludeApiStep(): void
    {
        $section = new SkillsSection();
        $out = $section->render([
            'skill_catalog' => [
                [
                    'skill_key' => 'inventory',
                    'name' => '库存查询',
                    'description' => '',
                    'task_kinds' => [],
                    'executor' => 'http_api',
                    'api_params' => [],
                ],
            ],
        ]);

        $this->assertStringContainsString('execute_api_skill', $out);
        $this->assertStringContainsString('[api]', $out);
    }

    public function testHttpApiWithoutParamsRendersNoBulletLines(): void
    {
        $section = new SkillsSection();
        $out = $section->render([
            'skill_catalog' => [
                [
                    'skill_key' => 'ping',
                    'name' => 'Ping',
                    'description' => '健康检查',
                    'task_kinds' => [],
                    'executor' => 'http_api',
                    'api_params' => [],
                ],
            ],
        ]);

        $this->assertStringNotContainsString('    · ', $out);
    }
}
