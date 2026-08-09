<?php

namespace Zephyrisle\FlarumZaiBot\Listener;

use Flarum\Messages\DialogMessage\Event\Created;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Zephyrisle\FlarumZaiBot\Job\GenerateReplyForMessage;

class ReplyToMessage
{
    /**
     * 请求级机器人用户 ID 缓存：同一请求内多条消息只查询一次数据库。
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

    public function handle(object $event): void
    {
        if (!($event instanceof Created)) {
            return;
        }

        // 机器人自己发出的消息（含任务内派发的 Created 事件）不触发新的回复任务，
        // 否则会产生无意义的队列任务并触发 getBotUser 的额外写入。
        $botUsername = $this->settings->get('flarum-zai-bot.username', 'AIGirl');

        if (!array_key_exists($botUsername, static::$botUserIdCache)) {
            $botUser = User::where('username', $botUsername)->first();
            static::$botUserIdCache[$botUsername] = $botUser ? (int) $botUser->id : null;
        }

        if (static::$botUserIdCache[$botUsername] !== null
            && (int) $event->message->user_id === static::$botUserIdCache[$botUsername]) {
            return;
        }

        $this->bus->dispatch(
            new GenerateReplyForMessage($event->message->id)
        );
    }
}
