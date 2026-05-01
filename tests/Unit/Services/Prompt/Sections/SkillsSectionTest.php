<?php

namespace Tests\Unit\Services\Prompt\Sections;

use App\Services\Prompt\Sections\SkillsSection;
use PHPUnit\Framework\TestCase;

class SkillsSectionTest extends TestCase
{
    public function testEmptyCatalogProducesNoBlock(): void
    {
        $section = new SkillsSection();
        $this->assertSame('', $section->render([]));
        $this->assertSame('', $section->render(['skill_catalog' => []]));
        $this->assertSame('', $section->render(['skill_catalog' => null]));
    }

    public function testRenderIncludesSkillKeyAndExecutorLabel(): void
    {
        $section = new SkillsSection();
        $out = $section->render([
            'skill_catalog' => [
                [
                    'skill_key' => 'calc',
                    'name' => '计算器',
                    'description' => '一个简单的计算器',
                    'task_kinds' => ['math', 'arithmetic'],
                    'executor' => 'sandbox',
                ],
            ],
        ]);

        $this->assertStringContainsString('<skills>', $out);
        $this->assertStringContainsString('</skills>', $out);
        $this->assertStringContainsString('/calc', $out);
        $this->assertStringContainsString('[sandbox]', $out);
        $this->assertStringContainsString('计算器', $out);
        $this->assertStringContainsString('math, arithmetic', $out);
        $this->assertStringContainsString('load_skill', $out);
        $this->assertStringContainsString('execute_sandbox_skill', $out);
    }

    public function testNonSandboxExecutorShowsInstructionLabel(): void
    {
        $section = new SkillsSection();
        $out = $section->render([
            'skill_catalog' => [
                [
                    'skill_key' => 'story',
                    'name' => 'Story helper',
                    'description' => '',
                    'task_kinds' => [],
                    'executor' => 'llm',
                ],
            ],
        ]);

        $this->assertStringContainsString('- /story', $out);
        $this->assertStringContainsString('[instruction]', $out);
        // [sandbox] literal still appears in the usage instructions block; check the skill line itself:
        $this->assertStringNotContainsString('/story — Story helper [sandbox]', $out);
    }

    public function testSkipsEntriesWithoutSkillKey(): void
    {
        $section = new SkillsSection();
        $out = $section->render([
            'skill_catalog' => [
                ['skill_key' => '', 'name' => 'Bad entry', 'description' => '', 'task_kinds' => [], 'executor' => 'llm'],
                ['skill_key' => 'good', 'name' => 'Good entry', 'description' => '', 'task_kinds' => [], 'executor' => 'llm'],
            ],
        ]);

        $this->assertStringNotContainsString('Bad entry', $out);
        $this->assertStringContainsString('/good', $out);
    }
}
