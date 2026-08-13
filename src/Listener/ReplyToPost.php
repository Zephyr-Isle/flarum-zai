<?php

namespace Zephyrisle\FlarumZaiBot\Listener;

use Flarum\Post\Event\Posted;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Zephyrisle\FlarumZaiBot\Job\GenerateReplyForPost;

class ReplyToPost
{
    /**
     * 请求级机器人用户 ID 缓存：同一请求内多篇帖子只查询一次数据库。
     * key 为用户名，值为用户 ID 或 null（机器人尚未创建）。
     */
    protected static array $botUserIdCache = [];

    public function __construct(
        protected Dispatcher $bus,
        protected SettingsRepositoryInterface $settings
    ) {}

    public static function clearBotUserCache(): void
    {
        static::$botUserIdCache = [];
    }

    public function handle(Posted $event): void
    {
        // 机器人自己发出的帖子（任务内派发的 Posted 事件）不触发新的回复任务，
        // 否则每个机器人回复都会产生一个无意义的队列任务并触发 getBotUser 的额外写入。
        $botUsername = $this->settings->get('flarum-zai-bot.username', 'AIGirl');

        if (!array_key_exists($botUsername, static::$botUserIdCache)) {
            $botUser = User::where('username', $botUsername)->first();
            static::$botUserIdCache[$botUsername] = $botUser ? (int) $botUser->id : null;
        }

        if (static::$botUserIdCache[$botUsername] !== null
            && (int) $event->post->user_id === static::$botUserIdCache[$botUsername]) {
            return;
        }

        $job = new GenerateReplyForPost($event->post->id);

        // 请求编排：硬等待消息合并。窗口 > 0 时延迟派发任务，先收集窗口内的后续帖子
        // 再统一发起请求（见 GenerateReplyForPost 的合并逻辑）。
        $mergeSeconds = max(0, (int) $this->settings->get('flarum-zai-bot.wake_merge_seconds', 0));
        if ($mergeSeconds > 0) {
            $job = $job->delay($mergeSeconds);
        }

        $this->bus->dispatch($job);
    }
}
