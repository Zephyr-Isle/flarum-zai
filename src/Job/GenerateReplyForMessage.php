<?php

namespace Zephyrisle\FlarumZaiBot\Job;

use Carbon\Carbon;
use Flarum\Messages\DialogMessage;
use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Zephyrisle\FlarumZaiBot\Service\AIService;

class GenerateReplyForMessage extends AbstractJob
{
    public int $messageId;

    public function __construct(int $messageId)
    {
        $this->messageId = $messageId;
    }

    public function handle(AIService $ai, SettingsRepositoryInterface $settings): void
    {
        $message = DialogMessage::find($this->messageId);

        if (!$message || !$message->dialog) {
            return;
        }

        $dialog = $message->dialog;

        if (!(bool) $settings->get('flarum-zai-bot.message_reply_enabled', false)) {
            return;
        }

        $botUsername = $settings->get('flarum-zai-bot.username', 'AIGirl');

        $botUser = User::where('username', $botUsername)->first();

        if (!$botUser) {
            $botUser = new User();
            $botUser->username = $botUsername;
            $botUser->email = $botUsername . '@bot.local';
            $botUser->password = \Illuminate\Support\Str::random(40);
            $botUser->is_email_confirmed = true;
            $botUser->save();

            $botUser->groups()->sync([1]);
        }

        $botUser->last_seen_at = Carbon::now();
        $botUser->save();

        if ($message->user_id === $botUser->id) {
            return;
        }

        $dialogUserIds = $dialog->users()->pluck('user_id')->toArray();

        if (!in_array($botUser->id, $dialogUserIds, true)) {
            return;
        }

        $reply = $ai->generateReply($message->content);

        if (!$reply) {
            return;
        }

        $botMessage = new DialogMessage();
        $botMessage->dialog_id = $dialog->id;
        $botMessage->user_id = $botUser->id;
        $botMessage->content = $reply;
        $botMessage->save();

        $botMessage->refresh();

        $dialog->setLastMessage($botMessage);
        $dialog->save();
    }
}
