<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zephyrisle\FlarumZaiBot\Service\Wake\MentionRule;

class MentionRuleTest extends TestCase
{
    public function testKeywordRuleMatchesSubstringCaseInsensitive(): void
    {
        $rule = MentionRule::parse('小米');

        $this->assertNotNull($rule);
        $this->assertTrue($rule->matches('今天买了小米手机', 1, 1));
        $this->assertTrue($rule->matches('小米14发布了', 1, 1));
        $this->assertFalse($rule->matches('苹果发布了新机', 1, 1));
    }

    public function testRegexRuleMatches(): void
    {
        $rule = MentionRule::parse('re:^(?=.*群主)(?=.*笨蛋).*$');

        $this->assertNotNull($rule);
        $this->assertTrue($rule->matches('群主是个笨蛋', 1, 1));
        $this->assertFalse($rule->matches('群主英明神武', 1, 1));
    }

    public function testRegexIsCaseInsensitive(): void
    {
        $rule = MentionRule::parse('re:hello');

        $this->assertNotNull($rule);
        $this->assertTrue($rule->matches('Hello World', 1, 1));
        $this->assertTrue($rule->matches('say hello', 1, 1));
    }

    public function testDiscussionWhitelistScope(): void
    {
        $rule = MentionRule::parse('关键字 @g:123,456');

        $this->assertNotNull($rule);
        $this->assertTrue($rule->matches('这是关键字', 123, 1));
        $this->assertTrue($rule->matches('这是关键字', 456, 1));
        $this->assertFalse($rule->matches('这是关键字', 789, 1));
    }

    public function testDiscussionBlacklistScope(): void
    {
        $rule = MentionRule::parse('关键字 @g:!456');

        $this->assertNotNull($rule);
        $this->assertFalse($rule->matches('这是关键字', 456, 1));
        $this->assertTrue($rule->matches('这是关键字', 123, 1));
    }

    public function testUserWhitelistScope(): void
    {
        $rule = MentionRule::parse('关键字 @u:10001,10002');

        $this->assertNotNull($rule);
        $this->assertTrue($rule->matches('这是关键字', 1, 10001));
        $this->assertTrue($rule->matches('这是关键字', 1, 10002));
        $this->assertFalse($rule->matches('这是关键字', 1, 10003));
    }

    public function testUserBlacklistScope(): void
    {
        $rule = MentionRule::parse('关键字 @u:!10001');

        $this->assertNotNull($rule);
        $this->assertFalse($rule->matches('这是关键字', 1, 10001));
        $this->assertTrue($rule->matches('这是关键字', 1, 20000));
    }

    public function testCombinedScopesMustBothHold(): void
    {
        $rule = MentionRule::parse('关键字 @g:123 @u:10001');

        $this->assertNotNull($rule);
        $this->assertTrue($rule->matches('这是关键字', 123, 10001));
        $this->assertFalse($rule->matches('这是关键字', 123, 99999));
        $this->assertFalse($rule->matches('这是关键字', 999, 10001));
    }

    public function testUserScopedRuleDoesNotMatchWhenUserUnknown(): void
    {
        $rule = MentionRule::parse('关键字 @u:10001');

        $this->assertNotNull($rule);
        $this->assertFalse($rule->matches('这是关键字', 1, null));
    }

    public function testRegexEscapesDelimiter(): void
    {
        // 用户正则可含 ~，应被转义而不破坏定界符
        $rule = MentionRule::parse('re:a~b');

        $this->assertNotNull($rule);
        $this->assertTrue($rule->matches('xa~bz', 1, 1));
        $this->assertFalse($rule->matches('ab', 1, 1));
    }

    public function testEmptyCommentAndInvalidLinesAreSkipped(): void
    {
        $rules = MentionRule::parseRules("# 注释\n\n   \n小米\nre:糟糕(未闭合\n关键字 @g:123\n");

        $this->assertCount(2, $rules);
        $this->assertSame('小米', $rules[0]->description());
        $this->assertSame('关键字 @g:123', $rules[1]->description());
    }

    public function testParseRulesAcceptsTrailingWhitespace(): void
    {
        $rules = MentionRule::parseRules("小米  \n");

        $this->assertCount(1, $rules);
        $this->assertTrue($rules[0]->matches('小米', 1, 1));
    }
}
