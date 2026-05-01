<?php

namespace Tests\Unit\Modules\ProactiveReminder;

use App\Modules\ProactiveReminder\Support\MessageCanonicalizer;
use PHPUnit\Framework\TestCase;

class MessageCanonicalizerTest extends TestCase
{
    public function testHashTreatsEquivalentReminderTextAsTheSameMessage(): void
    {
        $canonicalizer = new MessageCanonicalizer();

        $first = "用户A总，刚收到同事消息，需要我帮您对接吗？";
        $second = "  用户A总，刚收到同事消息，需要我帮您对接吗？  ";

        $this->assertSame($canonicalizer->hash($first), $canonicalizer->hash($second));
    }

    public function testNormalizeRemovesLinksAndCollapsesWhitespace(): void
    {
        $canonicalizer = new MessageCanonicalizer();

        $normalized = $canonicalizer->normalize("请查看  https://example.com/a \n\n 并尽快处理");

        $this->assertSame('请查看 并尽快处理', $normalized);
    }
}
