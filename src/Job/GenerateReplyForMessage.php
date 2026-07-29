<?php

namespace Zephyrisle\FlarumZaiBot\Job;

use Carbon\Carbon;
use Flarum\Messages\DialogMessage;
use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Zephyrisle\FlarumZaiBot\Service\AIService;
use Zephyrisle\FlarumZaiBot\Service\BotAccountManager;
use Zephyrisle\FlarumZaiBot\Service\Memory\MemoryManager;
use Zephyrisle\FlarumZaiBot\Service\Tool\LikeTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\SearchTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\StickerTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\UserInfoTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\ViewFileTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\WebSearchTool;

class GenerateReplyForMessage extends AbstractJob
{
    public int $messageId;

    public function __construct(int $messageId)
    {
        $this->messageId = $messageId;
    }

    public function handle(
        AIService $ai,
        SettingsRepositoryInterface $settings,
        BotAccountManager $accountManager,
        MemoryManager $memory
    ): void {
        $message = DialogMessage::find($this->messageId);
        if (!$message || !$message->dialog) return;

        $dialog = $message->dialog;

        if (!(bool) $settings->get('flarum-zai-bot.message_reply_enabled', false)) return;

        $account = $accountManager->getActiveAccount();
        if (!$account) return;

        $botUser = $accountManager->getOrCreateBotUser($account['username']);

        if ($message->user_id === $botUser->id) return;

        $dialogUserIds = $dialog->users()->pluck('user_id')->toArray();
        if (!in_array($botUser->id, $dialogUserIds, true)) return;

        $author = $message->user;
        $isVerified = false;
        if ($author && class_exists(\Ramon\Verified\TierResolver::class)) {
            $isVerified = resolve(\Ramon\Verified\TierResolver::class)->isVerified($author);
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
            'username' => $author ? $author->username : '',
            'display_name' => $author ? $author->display_name : '',
            'is_verified' => $isVerified,
            'conversation_history' => $history,
        ];

        if ($author) {
            $context['joined_at'] = $author->joined_at?->format('Y-m-d H:i:s');
            $context['post_count'] = $author->posts()->count();
            $context['group_names'] = $author->groups->pluck('name_singular')->implode(', ') ?: null;
            if (class_exists(\FoF\UserBio\Event\BioChanged::class) && $author->bio) {
                $context['bio'] = strip_tags($author->bio);
            }
            if (class_exists(\Ramon\Verified\Models\UserVerification::class)) {
                $v = \Ramon\Verified\Models\UserVerification::where('user_id', $author->id)->first();
                if ($v) {
                    $context['verified_tier'] = $v->verified_tier;
                    $context['verified_at'] = $v->verified_at?->format('Y-m-d H:i:s');
                }
            }
        }

        $memory->rememberUser($botUser->id, $author, ['last_interaction' => '私信']);
        $memory->rememberInteraction($botUser->id, "与 {$author->display_name} 私信对话");

        $tools = [
            new UserInfoTool(),
            new SearchTool(),
            new ViewFileTool(),
            new StickerTool(),
            new LikeTool($botUser->id),
            new WebSearchTool(),
        ];

        $reply = $ai->generateReply($message->content, $context, $tools, $account);

        if (!$reply) return;

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
