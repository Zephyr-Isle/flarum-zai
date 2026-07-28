<?php

namespace Zephyrisle\FlarumZaiBot\Listener;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Zephyrisle\FlarumZaiBot\Service\AIService;

class ReplyToMessage
{
    private static bool $processing = false;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected AIService $ai,
        protected Dispatcher $events
    ) {}

    public function handle(object $event): void
    {
        if (self::$processing) {
            return;
        }

        if (!($event instanceof \Flarum\Messages\DialogMessage\Event\Created)) {
            return;
        }

        self::$processing = true;

        try {
            $this->process($event);
        } finally {
            self::$processing = false;
        }
    }

    protected function process(\Flarum\Messages\DialogMessage\Event\Created $event): void
    {
        $message = $event->message;
        $dialog = $message->dialog()->first();

        if (!(bool) $this->settings->get('flarum-zai-bot.message_reply_enabled', false)) {
            return;
        }

        $botUsername = $this->settings->get('flarum-zai-bot.username', 'AIGirl');

        $botUser = User::where('username', $botUsername)->first();

        if (!$botUser) {
            $botUser = new User();
            $botUser->username = $botUsername;
            $botUser->email = $botUsername . '@bot.local';
            $botUser->password = \Illuminate\Support\Str::random(40);
            $botUser->is_email_confirmed = true;
            $botUser->save();
        }

        if ($message->user_id === $botUser->id) {
            return;
        }

        $dialogUserIds = $dialog->users()->pluck('user_id')->toArray();

        if (!in_array($botUser->id, $dialogUserIds, true)) {
            return;
        }

        $reply = $this->ai->generateReply($message->content);

        if (!$reply) {
            return;
        }

        try {
            $botMessage = new \Flarum\Messages\DialogMessage();
            $botMessage->dialog_id = $dialog->id;
            $botMessage->user_id = $botUser->id;
            $botMessage->content = $reply;
            $botMessage->save();

            $botMessage->refresh();

            $dialog->setLastMessage($botMessage);
            $dialog->save();

            $this->events->dispatch(
                new \Flarum\Messages\DialogMessage\Event\Created($botMessage)
            );
        } catch (\Exception $e) {
        }
    }
}
