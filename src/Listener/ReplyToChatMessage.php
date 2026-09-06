<?php

namespace Zephyrisle\FlarumZaiBot\Listener;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Zephyrisle\FlarumZaiBot\Job\GenerateReplyForChatMessage;

/**
 * ramon/chat 聊天消息监听器。
 *
 * 由 MessageWasSent 事件触发并派发 GenerateReplyForChatMessage 队列任务
 * （异步生成回复，保证不阻塞发送链路）。仅响应普通文本消息，机器人自己
 * 发出的消息（含兜底直接写入路径派发的事件）会被识别并跳过，避免循环。
 *
 * 注册前提是 ramon-chat 扩展已启用（见 extend.php 的 Conditional 块），
 * 故此处可以安全引用 Ramon\Chat\Event\MessageWasSent。
 */
class ReplyToChatMessage
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
        if (!($event instanceof \Ramon\Chat\Event\MessageWasSent)) {
            return;
        }

        // 系统/机器人消息不派发任务
        if ($event->message->type !== \Ramon\Chat\Message::TYPE_TEXT || $event->message->user_id === null) {
            return;
        }

        // 机器人自己发出的消息不触发新的回复任务，避免无意义的队列任务
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
            new GenerateReplyForChatMessage($event->message->id)
        );
    }
}