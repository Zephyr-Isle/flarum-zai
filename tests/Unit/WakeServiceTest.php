<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use Flarum\Settings\SettingsRepositoryInterface;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Zephyrisle\FlarumZaiBot\Service\Wake\WakeService;

class WakeServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * secondsSinceLastBotReply 取“刚回复过”的值，避免沉默补偿干扰基线用例。
     */
    private const RECENT_REPLY_SECONDS = 60;

    protected function settings(array $values = []): SettingsRepositoryInterface
    {
        $settings = Mockery::mock(SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->andReturnUsing(function (string $key, mixed $default = null) use ($values) {
            return array_key_exists($key, $values) ? $values[$key] : $default;
        });

        return $settings;
    }

    protected function service(array $values = []): WakeService
    {
        return new WakeService($this->settings($values));
    }

    protected function decide(
        WakeService $service,
        string $content,
        array $history = [],
        int $randomChance = 0,
        ?int $secondsSinceLastBotReply = self::RECENT_REPLY_SECONDS,
        bool $repliesToPost = false
    ) {
        return $service->detect(
            $content,
            1,
            10001,
            $history,
            $randomChance,
            'AIGirl',
            $secondsSinceLastBotReply,
            $repliesToPost,
            count($history)
        );
    }

    public function testMentionAlwaysTriggers(): void
    {
        $decision = $this->decide($this->service(), '@AIGirl 你好');

        $this->assertTrue($decision->reply);
        $this->assertSame('mention', $decision->type);
    }

    public function testEmptyMentionTriggers(): void
    {
        $decision = $this->decide($this->service(), '@AIGirl');

        $this->assertTrue($decision->reply);
        $this->assertSame('mention', $decision->type);
    }

    public function testNoWakeConditionByDefault(): void
    {
        $decision = $this->decide($this->service(), '今天天气不错');

        $this->assertFalse($decision->reply);
    }

    public function testMentionRuleKeywordTriggers(): void
    {
        $service = $this->service([
            'flarum-zai-bot.wake_mention_rules_enabled' => true,
            'flarum-zai-bot.wake_mention_rules' => "小米\nre:^(?=.*群主)(?=.*笨蛋).*$",
        ]);

        $this->assertTrue($this->decide($service, '小米发布了新手机')->reply);
        $this->assertTrue($this->decide($service, '群主是个笨蛋吧')->reply);
        $this->assertFalse($this->decide($service, '苹果发布了新手机')->reply);
    }

    public function testMentionRuleRespectsScope(): void
    {
        $service = $this->service([
            'flarum-zai-bot.wake_mention_rules_enabled' => true,
            'flarum-zai-bot.wake_mention_rules' => '关键字 @g:123 @u:10001',
        ]);

        $decision = $service->detect('这是关键字', 123, 10001, [], 0, 'AIGirl', self::RECENT_REPLY_SECONDS, false, 0);
        $this->assertTrue($decision->reply);

        $decision = $service->detect('这是关键字', 999, 10001, [], 0, 'AIGirl', self::RECENT_REPLY_SECONDS, false, 0);
        $this->assertFalse($decision->reply);

        $decision = $service->detect('这是关键字', 123, 99999, [], 0, 'AIGirl', self::RECENT_REPLY_SECONDS, false, 0);
        $this->assertFalse($decision->reply);
    }

    public function testMentionRulesDisabledWhenToggleOff(): void
    {
        $service = $this->service([
            'flarum-zai-bot.wake_mention_rules_enabled' => false,
            'flarum-zai-bot.wake_mention_rules' => '小米',
        ]);

        $this->assertFalse($this->decide($service, '小米发布了新手机')->reply);
    }

    public function testProbabilityWakeWithHundredPercent(): void
    {
        $decision = $this->decide($this->service(), '随便聊聊', [], 100);

        $this->assertTrue($decision->reply);
        $this->assertSame('probability', $decision->type);
    }

    public function testProbabilityDisabledAtZero(): void
    {
        $decision = $this->decide($this->service(), '随便聊聊', [], 0);

        $this->assertFalse($decision->reply);
    }

    public function testRelevanceWakesOnTopicOverlap(): void
    {
        $service = $this->service(['flarum-zai-bot.wake_relevance_enabled' => true]);
        $history = [['content' => '我们讨论一下如何优化数据库查询性能', 'author' => '甲']];

        // 与上文共享多个词、且非问句 → 相关性唤醒
        $decision = $this->decide($service, '数据库查询的优化方向', $history);

        $this->assertTrue($decision->reply);
        $this->assertSame('relevance', $decision->type);
    }

    public function testRelevanceDenoisesShortMessage(): void
    {
        $service = $this->service(['flarum-zai-bot.wake_relevance_enabled' => true]);
        $history = [['content' => '我们讨论一下数据库查询性能优化', 'author' => '甲']];

        // 超短消息 → 降噪
        $this->assertFalse($this->decide($service, '对', $history)->reply);
    }

    public function testRelevanceDenoisesQuestion(): void
    {
        $service = $this->service(['flarum-zai-bot.wake_relevance_enabled' => true]);
        $history = [['content' => '我们讨论一下数据库查询性能优化', 'author' => '甲']];

        // 问句 → 相关性降噪（交由专业答疑处理）
        $this->assertFalse($this->decide($service, '数据库查询还能怎么优化吗', $history)->reply);
    }

    public function testExpertWakesOnQuestionPlusHelpKeywords(): void
    {
        $service = $this->service(['flarum-zai-bot.wake_expert_enabled' => true]);

        $decision = $this->decide($service, '这个报错怎么解决，帮我看看');

        $this->assertTrue($decision->reply);
        $this->assertSame('expert', $decision->type);
    }

    public function testExpertRequiresQuestionThreshold(): void
    {
        $service = $this->service(['flarum-zai-bot.wake_expert_enabled' => true]);

        // 陈述句即使含关键词也不算提问
        $this->assertFalse($this->decide($service, '我来帮忙看看这个问题')->reply);
    }

    public function testExpertRequiresSemanticScore(): void
    {
        $service = $this->service(['flarum-zai-bot.wake_expert_enabled' => true]);

        // 是问句但缺少求助关键词（语义分不足）→ 不唤醒
        $this->assertFalse($this->decide($service, '这个怎么样')->reply);
    }

    public function testBoredomWakesOnColdSceneSignal(): void
    {
        $service = $this->service(['flarum-zai-bot.wake_boredom_enabled' => true]);

        $decision = $this->decide($service, '好无聊啊，有人吗');

        $this->assertTrue($decision->reply);
        $this->assertSame('boredom', $decision->type);
    }

    public function testBoredomFiltersLongNarrative(): void
    {
        $service = $this->service(['flarum-zai-bot.wake_boredom_enabled' => true]);

        $long = str_repeat('今天的天气真的很不错，阳光明媚，适合出去走走，', 10) . '好无聊';

        $this->assertFalse($this->decide($service, $long)->reply);
    }

    public function testRhythmDownweightsMentioningOthers(): void
    {
        $service = $this->service(['flarum-zai-bot.wake_relevance_enabled' => true]);
        $history = [['content' => '我们讨论一下数据库查询性能优化', 'author' => '甲']];

        // 相关性命中但消息在 @ 别人 → 降权后不唤醒
        $this->assertFalse($this->decide($service, '数据库查询优化 @小明', $history)->reply);
    }

    public function testRhythmDownweightsReplyingToOtherPost(): void
    {
        $service = $this->service(['flarum-zai-bot.wake_relevance_enabled' => true]);
        $history = [['content' => '我们讨论一下数据库查询性能优化', 'author' => '甲']];

        $this->assertFalse($this->decide($service, '数据库查询的优化方向', $history, 0, self::RECENT_REPLY_SECONDS, true)->reply);
    }

    public function testRhythmDownweightsRepeatedContextSnippet(): void
    {
        $service = $this->service(['flarum-zai-bot.wake_relevance_enabled' => true]);
        $history = [['content' => '数据库查询的优化方向', 'author' => '甲']];

        // 复读上文片段 → 相关性命中但被额外降权
        $this->assertFalse($this->decide($service, '数据库查询的优化方向', $history)->reply);
    }

    public function testSilenceCompensationHelpsBorderlineExpert(): void
    {
        $service = $this->service(['flarum-zai-bot.wake_expert_enabled' => true]);

        // 未触发：刚回复过（无补偿），语义分 1 < 2
        $this->assertFalse($this->decide($service, '这个怎么弄', [], 0, self::RECENT_REPLY_SECONDS)->reply);

        // 触发：长时间未参与（沉默补偿 +1）→ 2 >= 2
        $this->assertTrue($this->decide($service, '这个怎么弄', [], 0, null)->reply);
    }

    public function testExplicitMentionIgnoresRhythmDownweight(): void
    {
        $service = $this->service(['flarum-zai-bot.wake_relevance_enabled' => true]);
        $history = [['content' => '数据库查询还能怎么优化', 'author' => '甲']];

        // 显式 @ 机器人不受节奏降权影响
        $this->assertTrue($this->decide($service, '@AIGirl 数据库查询还能怎么优化', $history)->reply);
    }
}
