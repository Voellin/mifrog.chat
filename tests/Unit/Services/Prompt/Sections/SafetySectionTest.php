<?php

namespace Tests\Unit\Services\Prompt\Sections;

use App\Services\Prompt\Sections\SafetySection;
use PHPUnit\Framework\TestCase;

class SafetySectionTest extends TestCase
{
    public function testVerificationClauseIsPresent(): void
    {
        $s = new SafetySection();
        $out = $s->render();

        $this->assertStringContainsString('<verification>', $out);
        $this->assertStringContainsString('交付前请自检', $out);
        $this->assertStringContainsString('不允许从历史 assistant 回合的记忆里复用具体值', $out);
    }

    public function testLegacySafetyRulesPreserved(): void
    {
        $s = new SafetySection();
        $out = $s->render();

        $this->assertStringContainsString('Never invent ids, attendees, times', $out);
        $this->assertStringContainsString('Do not greet, do not apologize, do not use markdown', $out);
    }
}
