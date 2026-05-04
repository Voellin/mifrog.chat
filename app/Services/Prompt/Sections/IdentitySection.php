<?php

namespace App\Services\Prompt\Sections;

/**
 * Renders the <identity> block at the top of the system prompt. Reads the
 * persona markdown from soul/mifrog.md at repository root and caches the
 * contents in a static variable so we do not re-read the file on every call.
 * If the file is missing for any reason, falls back to a hardcoded one-line
 * identity so the agent never ships without a persona.
 */
class IdentitySection
{
    private static ?string $cachedBody = null;

    private const FALLBACK_BODY = 'You are MiFrog, an enterprise assistant focused on Feishu execution tasks.';

    /**
     * @param  array<string,mixed>  $context
     */
    public function render(array $context = []): string
    {
        $body = $this->loadBody();
        return "<identity>\n".rtrim($body)."\n</identity>";
    }

    private function loadBody(): string
    {
        if (self::$cachedBody !== null) {
            return self::$cachedBody;
        }

        $path = $this->resolvePath();
        if ($path !== null && is_file($path) && is_readable($path)) {
            $raw = @file_get_contents($path);
            if (is_string($raw) && trim($raw) !== '') {
                self::$cachedBody = $raw;
                return self::$cachedBody;
            }
        }

        self::$cachedBody = self::FALLBACK_BODY;
        return self::$cachedBody;
    }

    private function resolvePath(): ?string
    {
        // base_path() is only available once the Laravel container is booted;
        // tests sometimes instantiate the section without the container, so we
        // guard against that and synthesize a path relative to this file.
        if (function_exists('base_path')) {
            try {
                return base_path('soul/mifrog.md');
            } catch (\Throwable) {
                // fall through
            }
        }

        return __DIR__.'/../../../../soul/mifrog.md';
    }

    /**
     * Test hook: clear the cached body so tests can exercise different states.
     */
    public static function resetCache(): void
    {
        self::$cachedBody = null;
    }
}
