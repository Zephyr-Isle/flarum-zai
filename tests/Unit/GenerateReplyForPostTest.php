<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use Flarum\Settings\SettingsRepositoryInterface;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Zephyrisle\FlarumZaiBot\Job\GenerateReplyForPost;

class GenerateReplyForPostTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var SettingsRepositoryInterface&Mockery\MockInterface */
    protected $settings;

    protected function setUp(): void
    {
        $this->settings = Mockery::mock(SettingsRepositoryInterface::class);
    }

    /**
     * 匿名子类暴露受保护的方法供测试调用。
     */
    protected function job(): GenerateReplyForPost
    {
        return new class (1) extends GenerateReplyForPost {
            public function exposeCooldown(SettingsRepositoryInterface $settings): int
            {
                return $this->getReplyCooldownSeconds($settings);
            }

            public function exposeRandomChance(SettingsRepositoryInterface $settings): int
            {
                return $this->getRandomReplyChance($settings);
            }

            public function exposeShouldSkipReply(string $reply): bool
            {
                return $this->shouldSkipReply($reply);
            }
        };
    }

    public function testCooldownDefaultsToThirtySeconds(): void
    {
        // 未配置时，应读取默认值 30
        $this->settings->shouldReceive('get')
            ->with('flarum-zai-bot.reply_cooldown', 30)
            ->andReturn(30);

        $this->assertSame(30, $this->job()->exposeCooldown($this->settings));
    }

    public function testCooldownUsesConfiguredValue(): void
    {
        $this->settings->shouldReceive('get')
            ->with('flarum-zai-bot.reply_cooldown', 30)
            ->andReturn(15);

        $this->assertSame(15, $this->job()->exposeCooldown($this->settings));
    }

    public function testCooldownZeroDisablesCooldown(): void
    {
        $this->settings->shouldReceive('get')
            ->with('flarum-zai-bot.reply_cooldown', 30)
            ->andReturn(0);

        $this->assertSame(0, $this->job()->exposeCooldown($this->settings));
    }

    public function testCooldownClampsNegativeValuesToZero(): void
    {
        $this->settings->shouldReceive('get')
            ->with('flarum-zai-bot.reply_cooldown', 30)
            ->andReturn(-5);

        $this->assertSame(0, $this->job()->exposeCooldown($this->settings));
    }

    public function testCooldownAcceptsNumericStrings(): void
    {
        $this->settings->shouldReceive('get')
            ->with('flarum-zai-bot.reply_cooldown', 30)
            ->andReturn('45');

        $this->assertSame(45, $this->job()->exposeCooldown($this->settings));
    }

    public function testRandomChanceDefaultsToZero(): void
    {
        $this->settings->shouldReceive('get')
            ->with('flarum-zai-bot.random_reply_chance', 0)
            ->andReturn(0);

        $this->assertSame(0, $this->job()->exposeRandomChance($this->settings));
    }

    public function testRandomChanceUsesConfiguredValue(): void
    {
        $this->settings->shouldReceive('get')
            ->with('flarum-zai-bot.random_reply_chance', 0)
            ->andReturn(30);

        $this->assertSame(30, $this->job()->exposeRandomChance($this->settings));
    }

    public function testRandomChanceClampsAboveOneHundred(): void
    {
        $this->settings->shouldReceive('get')
            ->with('flarum-zai-bot.random_reply_chance', 0)
            ->andReturn(150);

        $this->assertSame(100, $this->job()->exposeRandomChance($this->settings));
    }

    public function testRandomChanceClampsNegativeToZero(): void
    {
        $this->settings->shouldReceive('get')
            ->with('flarum-zai-bot.random_reply_chance', 0)
            ->andReturn(-20);

        $this->assertSame(0, $this->job()->exposeRandomChance($this->settings));
    }

    public function testRandomChanceAcceptsNumericStrings(): void
    {
        $this->settings->shouldReceive('get')
            ->with('flarum-zai-bot.random_reply_chance', 0)
            ->andReturn('45');

        $this->assertSame(45, $this->job()->exposeRandomChance($this->settings));
    }

    public function testSkipReplyDetectsSkipMarker(): void
    {
        $this->assertTrue($this->job()->exposeShouldSkipReply('[ZAI_SKIP]'));
    }

    public function testSkipReplyDetectsMarkerWithSurroundingText(): void
    {
        $this->assertTrue($this->job()->exposeShouldSkipReply("\n[ZAI_SKIP]\n"));
        $this->assertTrue($this->job()->exposeShouldSkipReply("无需回复。\n[ZAI_SKIP]"));
    }

    public function testSkipReplyAllowsNormalReplies(): void
    {
        $this->assertFalse($this->job()->exposeShouldSkipReply('好的，我来解答这个问题。'));
        $this->assertFalse($this->job()->exposeShouldSkipReply(''));
    }
}
