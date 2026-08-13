<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use Flarum\Settings\SettingsRepositoryInterface;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Zephyrisle\FlarumZaiBot\Model\ContextEvent;
use Zephyrisle\FlarumZaiBot\Service\Context\ContextInjectionService;

class ContextInjectionServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function tearDown(): void
    {
        ContextEvent::query()->delete();
    }

    protected function settings(array $values = []): SettingsRepositoryInterface
    {
        $settings = Mockery::mock(SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->andReturnUsing(function (string $key, mixed $default = null) use ($values) {
            return array_key_exists($key, $values) ? $values[$key] : $default;
        });

        return $settings;
    }

    protected function service(array $values = []): ContextInjectionService
    {
        return new ContextInjectionService($this->settings($values));
    }

    protected function baseContext(array $extra = []): array
    {
        return array_merge([
            'channel' => 'forum',
            'wake_type' => 'relevance',
            'discussion_id' => 7,
            'discussion_title' => '测试讨论',
            'user_id' => 10001,
            'username' => 'alice',
            'display_name' => '爱丽丝',
            'group_names' => 'Admin',
        ], $extra);
    }

    public function testOffTimingReturnsNull(): void
    {
        $service = $this->service(['flarum-zai-bot.ctx_inject_timing' => 'off']);

        $this->assertNull($service->buildInjectedContext($this->baseContext()));
        $this->assertFalse($service->shouldInject('relevance'));
    }

    public function testProactiveTimingSkipsMention(): void
    {
        $service = $this->service(['flarum-zai-bot.ctx_inject_timing' => 'proactive']);

        $this->assertTrue($service->shouldInject('relevance'));
        $this->assertTrue($service->shouldInject('probability'));
        $this->assertTrue($service->shouldInject('expert'));
        $this->assertTrue($service->shouldInject('boredom'));
        $this->assertFalse($service->shouldInject('mention'));
        $this->assertFalse($service->shouldInject('rule'));

        // mention 触发时不注入
        $this->assertNull($service->buildInjectedContext($this->baseContext(['wake_type' => 'mention'])));
        // 相关性唤醒时注入
        $this->assertNotNull($service->buildInjectedContext($this->baseContext()));
    }

    public function testAllTimingInjectsEveryWake(): void
    {
        $service = $this->service(['flarum-zai-bot.ctx_inject_timing' => 'all']);

        $this->assertTrue($service->shouldInject('mention'));
        $this->assertTrue($service->shouldInject('relevance'));
        $this->assertNotNull($service->buildInjectedContext($this->baseContext(['wake_type' => 'mention'])));
    }

    public function testMessageChannelInjectsUnlessOff(): void
    {
        $service = $this->service(['flarum-zai-bot.ctx_inject_timing' => 'proactive']);
        $ctx = $this->baseContext(['channel' => 'message', 'wake_type' => null, 'discussion_id' => null, 'discussion_title' => null]);

        $this->assertNotNull($service->buildInjectedContext($ctx));

        $off = $this->service(['flarum-zai-bot.ctx_inject_timing' => 'off']);
        $this->assertNull($off->buildInjectedContext($ctx));
    }

    public function testBlockContainsEnvironmentFields(): void
    {
        $block = $this->service()->buildInjectedContext($this->baseContext());

        $this->assertNotNull($block);
        $this->assertStringContainsString('Flarum 论坛', $block);
        $this->assertStringContainsString('论坛讨论帖', $block);
        $this->assertStringContainsString('讨论ID：7', $block);
        $this->assertStringContainsString('讨论标题：测试讨论', $block);
        $this->assertStringContainsString('发送者用户ID：10001', $block);
        $this->assertStringContainsString('爱丽丝', $block);
        $this->assertStringContainsString('用户组：Admin', $block);
        $this->assertStringContainsString('当前时间', $block);
    }

    public function testBlockContainsRecentEvents(): void
    {
        $this->seedEvents();

        $block = $this->service()->buildInjectedContext($this->baseContext());

        $this->assertNotNull($block);
        $this->assertStringContainsString('【近期事件】', $block);
        $this->assertStringContainsString('事件 #1 帖子 #41 被隐藏（撤回）', $block);
        $this->assertStringContainsString('事件 #2', $block);
    }

    public function testEventsLimitedToMax(): void
    {
        $this->seedEvents(5);

        $block = $this->service(['flarum-zai-bot.ctx_max_events' => 2])->buildInjectedContext($this->baseContext());

        $this->assertNotNull($block);
        // 只保留最近 2 条（id 4、5）
        $this->assertStringNotContainsString('事件 #1', $block);
        $this->assertStringNotContainsString('事件 #2', $block);
        $this->assertStringNotContainsString('事件 #3', $block);
        $this->assertStringContainsString('事件 #4', $block);
        $this->assertStringContainsString('事件 #5', $block);
    }

    public function testDetailedFormatIncludesStructure(): void
    {
        $this->seedEvents(1);

        $block = $this->service(['flarum-zai-bot.ctx_format' => 'detailed'])->buildInjectedContext($this->baseContext());

        $this->assertNotNull($block);
        $this->assertStringContainsString('msg_id=', $block);
        $this->assertStringContainsString('type=post_hidden', $block);
        $this->assertStringContainsString('actor=', $block);
    }

    public function testEntriesTruncatedToMaxChars(): void
    {
        $service = $this->service(['flarum-zai-bot.ctx_entry_max_chars' => 20]);
        $block = $service->buildInjectedContext($this->baseContext([
            'discussion_title' => str_repeat('非常长的讨论标题', 20),
        ]));

        $this->assertNotNull($block);
        $this->assertStringContainsString('…', $block);
    }

    public function testNoEventsWhenDiscussionAbsent(): void
    {
        $this->seedEvents(2);

        $block = $this->service()->buildInjectedContext($this->baseContext(['discussion_id' => 999]));

        $this->assertNotNull($block);
        $this->assertStringNotContainsString('【近期事件】', $block);
    }

    protected function seedEvents(int $count = 2): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $event = new ContextEvent();
            $event->discussion_id = 7;
            $event->post_id = 40 + $i;
            $event->user_id = 10001;
            $event->type = 'post_hidden';
            $event->description = "事件 #{$i} 帖子 #" . (40 + $i) . ' 被隐藏（撤回）';
            $event->save();
        }
    }
}
