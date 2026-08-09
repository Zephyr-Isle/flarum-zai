<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zephyrisle\FlarumZaiBot\Service\Tool\SearchTool;

class SearchToolTest extends TestCase
{
    /**
     * 匿名子类暴露受保护的转义方法。
     */
    protected function tool(): SearchTool
    {
        return new class extends SearchTool {
            public function exposeEscapeLike(string $query): string
            {
                return $this->escapeLike($query);
            }
        };
    }

    public function testEscapesPercentWildcard(): void
    {
        $this->assertSame('50\\% off', $this->tool()->exposeEscapeLike('50% off'));
    }

    public function testEscapesUnderscoreWildcard(): void
    {
        $this->assertSame('a\\_b', $this->tool()->exposeEscapeLike('a_b'));
    }

    public function testEscapesBackslash(): void
    {
        $this->assertSame('back\\\\slash', $this->tool()->exposeEscapeLike('back\\slash'));
    }

    public function testLeavesPlainTextUntouched(): void
    {
        $this->assertSame('普通文本', $this->tool()->exposeEscapeLike('普通文本'));
        $this->assertSame('hello world', $this->tool()->exposeEscapeLike('hello world'));
    }
}
