<?php

namespace Zephyrisle\FlarumZaiBot\Job;

use Carbon\Carbon;
use Flarum\Messages\DialogMessage;
use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Zephyrisle\FlarumZaiBot\Service\AIService;
use Zephyrisle\FlarumZaiBot\Service\Tool\SearchTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\UserInfoTool;

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

        $botUser = $this->getBotUser($botUsername);

        if ($message->user_id === $botUser->id) {
            return;
        }

        $dialogUserIds = $dialog->users()->pluck('user_id')->toArray();

        if (!in_array($botUser->id, $dialogUserIds, true)) {
            return;
        }

        $author = $message->user;
        $isVerified = false;
        if ($author && class_exists(\Ramon\Verified\TierResolver::class)) {
            $resolver = resolve(\Ramon\Verified\TierResolver::class);
            $isVerified = $resolver->isVerified($author);
        }

        $history = [];
        $recentMessages = DialogMessage::where('dialog_id', $dialog->id)
            ->where('id', '<', $message->id)
            ->orderBy('id', 'desc')
            ->take(10)
            ->get()
            ->reverse();

        foreach ($recentMessages as $prevMsg) {
            $prevAuthor = $prevMsg->user;
            $history[] = [
                'author' => $prevAuthor ? $prevAuthor->display_name : '未知',
                'content' => $prevMsg->content,
            ];
        }

        $context = [
            'username' => $author ? $author->username : 'unknown',
            'display_name' => $author ? $author->display_name : '未知',
            'is_verified' => $isVerified,
            'conversation_history' => $history,
        ];

        $tools = [new UserInfoTool(), new SearchTool()];

        $reply = $ai->generateReply($message->content, $context, $tools);

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

    protected function getBotUser(string $botUsername): User
    {
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

        return $botUser;
    }
}
