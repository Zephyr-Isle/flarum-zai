<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use Flarum\Post\CommentPost;
use Flarum\Post\Event\Posted;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Zephyrisle\FlarumZaiBot\Job\GenerateReplyForPost;
use Zephyrisle\FlarumZaiBot\Listener\ReplyToPost;

class ReplyToPostTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function tearDown(): void
    {
        // 清空 users 表与监听器静态缓存，避免测试间相互污染
        User::query()->delete();
        ReplyToPost::clearBotUserCache();
    }

    protected function postWith(int $id, int $userId): CommentPost
    {
        $post = new CommentPost();
        $post->setAttribute('id', $id);
        $post->setAttribute('user_id', $userId);

        return $post;
    }

    protected function settingsMock(): SettingsRepositoryInterface
    {
        $settings = Mockery::mock(SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->with('flarum-zai-bot.username', 'AIGirl')->andReturn('AIGirl');

        return $settings;
    }

    public function testDispatchesJobForHumanPost(): void
    {
        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(fn ($job) => $job instanceof GenerateReplyForPost && $job->postId === 42));

        (new ReplyToPost($bus, $this->settingsMock()))->handle(new Posted($this->postWith(42, 99)));
    }

    public function testSkipsJobForBotOwnPost(): void
    {
        $bot = new User();
        $bot->username = 'AIGirl';
        $bot->email = 'bot@bot.local';
        $bot->is_email_confirmed = true;
        $bot->save();

        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->never();

        (new ReplyToPost($bus, $this->settingsMock()))->handle(new Posted($this->postWith(7, $bot->id)));
    }

    public function testDispatchesWhenBotUserDoesNotExistYet(): void
    {
        // 机器人用户尚未创建：不应跳过（创建逻辑在 Job 内完成）
        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->once();

        (new ReplyToPost($bus, $this->settingsMock()))->handle(new Posted($this->postWith(5, 123)));
    }
}
