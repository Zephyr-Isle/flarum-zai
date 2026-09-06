<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zephyrisle\FlarumZaiBot\Service\Text\PastePlaceholder;

class PastePlaceholderTest extends TestCase
{
    public function testNormalizeReplacesKnownVariants(): void
    {
        $cases = [
            '[Pasted ~7 lines]',
            '[pasted 4 lines]',
            '[pasted #2 1+ lines]',
            '看看这个 [Pasted ~3 lines] 再说',
            "第一行\n[Pasted ~7 lines]\n最后一行",
        ];

        foreach ($cases as $input) {
            $out = PastePlaceholder::normalize($input);
            $this->assertStringContainsString('粘贴了大段文本', $out);
            $this->assertStringNotContainsString('pasted', strtolower($out));
            $this->assertStringNotContainsString('Pasted', $out);
        }
    }

    public function testNormalizeLeavesNormalTextUntouched(): void
    {
        $this->assertSame('你好，今天天气不错', PastePlaceholder::normalize('你好，今天天气不错'));
        $this->assertSame('', PastePlaceholder::normalize(''));
    }

    public function testScrubReplyClearsMarkers(): void
    {
        $out = PastePlaceholder::scrubReply('好的，我看到了 [Pasted ~7 lines] 的内容');
        $this->assertStringNotContainsString('[Pasted', $out);
        $this->assertStringContainsString('粘贴内容略', $out);
    }

    public function testScrubReplyLeavesNormalReplyUntouched(): void
    {
        $this->assertSame('好的没问题', PastePlaceholder::scrubReply('好的没问题'));
    }
}