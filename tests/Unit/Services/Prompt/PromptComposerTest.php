<?php

namespace Tests\Unit\Services\Prompt;

use App\Services\Prompt\PromptComposer;
use App\Services\Prompt\Sections\ChannelSection;
use App\Services\Prompt\Sections\IdentitySection;
use App\Services\Prompt\Sections\MemorySection;
use App\Services\Prompt\Sections\RuntimeSection;
use App\Services\Prompt\Sections\SafetySection;
use App\Services\Prompt\Sections\SkillsSection;
use App\Services\Prompt\Sections\ToolsSection;
use PHPUnit\Framework\TestCase;

class PromptComposerTest extends TestCase
{
    private function makeComposer(): PromptComposer
    {
        IdentitySection::resetCache();
        return new PromptComposer(
            new IdentitySection(),
            new ToolsSection(),
            new SkillsSection(),
            new SafetySection(),
            new MemorySection(),
            new ChannelSection(),
            new RuntimeSection(),
        );
    }

    public function testComposeInToolCallingModeContainsCoreSections(): void
    {
        $composer = $this->makeComposer();
        $out = $composer->compose([
            'mode' => 'tool_calling',
            'time_context' => 'Current time: 2026-04-18 10:00 CST',
        ]);

        $this->assertStringContainsString('<identity>', $out);
        $this->assertStringContainsString('<tools>', $out);
        $this->assertStringContainsString('<safety>', $out);
        $this->assertStringContainsString('<channel>', $out);
        $this->assertStringContainsString('<runtime>', $out);
        $this->assertStringContainsString('2026-04-18 10:00 CST', $out);
    }

    public function testComposeWithoutMemoryContextDoesNotEmitMemoryFence(): void
    {
        $composer = $this->makeComposer();
        $out = $composer->compose(['mode' => 'tool_calling']);

        $this->assertStringNotContainsString('<memory>', $out);
        $this->assertStringNotContainsString('<recent_references>', $out);
    }

    public function testComposeWithMemoryContextEmitsMemoryFence(): void
    {
        $composer = $this->makeComposer();
        $out = $composer->compose([
            'mode' => 'tool_calling',
            'memory_context' => 'Prior context summary',
        ]);

        $this->assertStringContainsString('<memory>', $out);
        $this->assertStringContainsString('Prior context summary', $out);
    }

    public function testSkillCatalogEmitsSkillsFenceWhenProvided(): void
    {
        $composer = $this->makeComposer();
        $out = $composer->compose([
            'mode' => 'tool_calling',
            'skill_catalog' => [
                ['skill_key' => 'calc', 'name' => 'Calc', 'description' => '', 'task_kinds' => [], 'executor' => 'sandbox'],
            ],
        ]);

        $this->assertStringContainsString('<skills>', $out);
        $this->assertStringContainsString('/calc', $out);
    }

    public function testComposeWithoutSkillCatalogOmitsSkillsFence(): void
    {
        $composer = $this->makeComposer();
        $out = $composer->compose(['mode' => 'tool_calling']);

        $this->assertStringNotContainsString('<skills>', $out);
    }

    public function testComposeEmitsIdentityFirst(): void
    {
        $composer = $this->makeComposer();
        $out = $composer->compose(['mode' => 'tool_calling']);
        $identityPos = strpos($out, '<identity>');
        $toolsPos = strpos($out, '<tools>');

        $this->assertIsInt($identityPos);
        $this->assertIsInt($toolsPos);
        $this->assertLessThan($toolsPos, $identityPos);
    }
}
