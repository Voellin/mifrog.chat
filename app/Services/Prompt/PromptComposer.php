<?php

namespace App\Services\Prompt;

use App\Services\Prompt\Sections\ChannelSection;
use App\Services\Prompt\Sections\IdentitySection;
use App\Services\Prompt\Sections\MemorySection;
use App\Services\Prompt\Sections\SkillsSection;
use App\Services\Prompt\Sections\RuntimeSection;
use App\Services\Prompt\Sections\SafetySection;
use App\Services\Prompt\Sections\ToolsSection;

/**
 * Assembles the full system prompt from independently testable Sections.
 * The composer is the only place that decides section order; each Section
 * owns its own wording and fence format.
 */
class PromptComposer
{
    public function __construct(
        private readonly IdentitySection $identity,
        private readonly ToolsSection $tools,
        private readonly SkillsSection $skills,
        private readonly SafetySection $safety,
        private readonly MemorySection $memory,
        private readonly ChannelSection $channel,
        private readonly RuntimeSection $runtime,
    ) {
    }

    /**
     * @param  array{
     *     mode?: string,
     *     time_context?: string,
     *     memory_context?: string,
     *     recent_references?: string|array<string,string>,
     *     channel?: string,
     *     run_metadata?: array<string,mixed>,
     * }  $context
     */
    public function compose(array $context = []): string
    {
        $parts = [
            $this->identity->render($context),
            $this->tools->render($context),
            $this->skills->render($context),
            $this->safety->render($context),
            $this->memory->render($context),
            $this->channel->render($context),
            $this->runtime->render($context),
        ];

        $filtered = array_filter($parts, fn ($p) => trim((string) $p) !== '');
        return implode("\n\n", $filtered);
    }
}
