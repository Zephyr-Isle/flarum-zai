<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use Flarum\Discussion\Event\Renamed;
use Flarum\Discussion\Event\Started;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Hidden as PostHidden;
use Flarum\Post\Event\Restored as PostRestored;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Zephyrisle\FlarumZaiBot\Listener\RecordContextEvent;
use Zephyrisle\FlarumZaiBot\Model\ContextEvent;

class RecordContextEventTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function tearDown(): void
    {
        ContextEvent::query()->delete();
        User::query()->delete();
    }

    protected function settings(bool $enabled = true): SettingsRepositoryInterface
    {
        $settings = Mockery::mock(SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->with('flarum-zai-bot.ctx_event_record_enabled', true)->andReturn($enabled);

        return $settings;
    }

    protected function post(int $id, int $discussionId): CommentPost
    {
        $post = new CommentPost();
        $post->setAttribute('id', $id);
        $post->setAttribute('discussion_id', $discussionId);
        $post->setAttribute('user_id', 10001);

        return $post;
    }

    protected function actor(): User
    {
        $user = new User();
        $user->setAttribute('id', 555);
        $user->username = 'moderator';
        $user->email = 'mod@local.test';
        $user->is_email_confirmed = true;

        return $user;
    }

    public function testRecordsPostHidden(): void
    {
        (new RecordContextEvent($this->settings()))->onPostHidden(new PostHidden($this->post(42, 7), $this->actor()));

        $event = ContextEvent::first();

        $this->assertNotNull($event);
        $this->assertSame(7, (int) $event->discussion_id);
        $this->assertSame(42, (int) $event->post_id);
        $this->assertSame(555, (int) $event->user_id);
        $this->assertSame('post_hidden', $event->type);
        $this->assertStringContainsString('帖子 #42 被隐藏（撤回）', $event->description);
    }

    public function testHandleDispatchesToOnPostHidden(): void
    {
        // Flarum 以类名注册监听器并调用 handle()，这里验证分发逻辑
        (new RecordContextEvent($this->settings()))->handle(new PostHidden($this->post(42, 7), $this->actor()));

        $event = ContextEvent::first();

        $this->assertNotNull($event);
        $this->assertSame('post_hidden', $event->type);
        $this->assertStringContainsString('帖子 #42 被隐藏（撤回）', $event->description);
    }

    public function testHandleDispatchesToOnDiscussionRenamed(): void
    {
        $discussion = new \Flarum\Discussion\Discussion();
        $discussion->setRawAttributes(['id' => 7, 'title' => '新标题'], true);

        (new RecordContextEvent($this->settings()))->handle(new Renamed($discussion, '旧标题', $this->actor()));

        $event = ContextEvent::first();

        $this->assertNotNull($event);
        $this->assertSame('discussion_renamed', $event->type);
        $this->assertStringContainsString('由「旧标题」改为「新标题」', $event->description);
    }

    public function testHandleIgnoresUnknownEvent(): void
    {
        (new RecordContextEvent($this->settings()))->handle(new \stdClass());

        $this->assertSame(0, ContextEvent::query()->count());
    }

    public function testRecordsPostRestored(): void
    {
        (new RecordContextEvent($this->settings()))->onPostRestored(new PostRestored($this->post(43, 7), $this->actor()));

        $event = ContextEvent::first();

        $this->assertNotNull($event);
        $this->assertSame('post_restored', $event->type);
    }

    public function testRecordsDiscussionStarted(): void
    {
        $discussion = new \Flarum\Discussion\Discussion();
        // setRawAttributes 绕过 setTitleAttribute 的容器 resolve（测试环境无容器绑定）
        $discussion->setRawAttributes(['id' => 7, 'title' => '新话题', 'user_id' => 10001], true);

        (new RecordContextEvent($this->settings()))->onDiscussionStarted(new Started($discussion, $this->actor()));

        $event = ContextEvent::first();

        $this->assertNotNull($event);
        $this->assertSame(7, (int) $event->discussion_id);
        $this->assertSame('discussion_started', $event->type);
        $this->assertStringContainsString('新讨论「新话题」创建', $event->description);
    }

    public function testRecordsDiscussionRenamed(): void
    {
        $discussion = new \Flarum\Discussion\Discussion();
        $discussion->setRawAttributes(['id' => 7, 'title' => '新标题'], true);

        (new RecordContextEvent($this->settings()))->onDiscussionRenamed(new Renamed($discussion, '旧标题', $this->actor()));

        $event = ContextEvent::first();

        $this->assertNotNull($event);
        $this->assertSame('discussion_renamed', $event->type);
        $this->assertStringContainsString('由「旧标题」改为「新标题」', $event->description);
    }

    public function testSkipsRecordingWhenDisabled(): void
    {
        (new RecordContextEvent($this->settings(false)))->onPostHidden(new PostHidden($this->post(42, 7), $this->actor()));

        $this->assertSame(0, ContextEvent::query()->count());
    }

    public function testNullActorStoredAsNull(): void
    {
        (new RecordContextEvent($this->settings()))->onPostHidden(new PostHidden($this->post(42, 7), null));

        $event = ContextEvent::first();

        $this->assertNotNull($event);
        $this->assertNull($event->user_id);
    }
}
