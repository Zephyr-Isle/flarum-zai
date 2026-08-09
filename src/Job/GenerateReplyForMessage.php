<?php

namespace Zephyrisle\FlarumZaiBot\Job;

use Flarum\Messages\DialogMessage;
use Flarum\Messages\DialogMessage\Event\Created;
use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Zephyrisle\FlarumZaiBot\Job\Concerns\BuildsBotTools;
use Zephyrisle\FlarumZaiBot\Job\Concerns\ManagesBotUser;
use Zephyrisle\FlarumZaiBot\Model\BotAffinity;
use Zephyrisle\FlarumZaiBot\Service\AIService;
use Zephyrisle\FlarumZaiBot\Service\MemoryService;
use Zephyrisle\FlarumZaiBot\Service\PortraitService;

class GenerateReplyForMessage extends AbstractJob
{
    use BuildsBotTools;
    use ManagesBotUser;

    public int $messageId;

    public function __construct(int $messageId)
    {
        $this->messageId = $messageId;
    }

    public function handle(AIService $ai, SettingsRepositoryInterface $settings, Dispatcher $events): void
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

        $affinity = null;
        $portraitSummary = null;
        $memories = [];
        $userId = $author ? $author->id : null;

        if ($author) {
            $affinity = BotAffinity::getOrCreate($author->id);

            try {
                $portraitService = resolve(PortraitService::class);
                $portraitSummary = $portraitService->getPortraitSummary($author->id);
            } catch (\Exception $e) {
            }

            try {
                $memoryService = resolve(MemoryService::class);
                if ($memoryService->isAvailable()) {
                    $embedding = $memoryService->generateEmbedding($message->content);
                    if ($embedding) {
                        $memories = $memoryService->searchMemories($author->id, $embedding, 5);
                    }
                }
            } catch (\Exception $e) {
            }
        }

        $context = [
            'channel' => 'message',
            'user_id' => $userId,
            'username' => $author ? $author->username : 'unknown',
            'display_name' => $author ? $author->display_name : '未知',
            'is_verified' => $isVerified,
            'affinity_score' => $affinity?->total_score ?? null,
            'portrait_summary' => $portraitSummary,
            'memories' => $memories,
            'conversation_history' => $history,
        ];

        if ($author) {
            $context['joined_at'] = $author->joined_at ? $author->joined_at->format('Y-m-d H:i:s') : null;
            $context['post_count'] = $author->posts()->count();
            $context['group_names'] = $author->groups->pluck('name_singular')->implode(', ') ?: null;

            if (class_exists(\FoF\UserBio\Event\BioChanged::class) && $author->bio) {
                $context['bio'] = strip_tags($author->bio);
            }

            if (class_exists(\Datlechin\Birthdays\AddBirthdayValidation::class) && $author->birthday) {
                $context['birthday'] = $author->birthday;
            }

            if (class_exists(\Ramon\Verified\Models\UserVerification::class)) {
                $verification = \Ramon\Verified\Models\UserVerification::where('user_id', $author->id)->first();
                if ($verification) {
                    $context['verified_tier'] = $verification->verified_tier;
                    $context['verified_at'] = $verification->verified_at ? $verification->verified_at->format('Y-m-d H:i:s') : null;
                }
            }
        }

        $tools = $this->buildBotTools($botUser->id, $userId, $settings);

        $reply = $ai->generateReply($message->content, $context, $tools);

        if ($reply && $userId) {
            $reply = $ai->parseSecretEval($reply, $userId);
        }

        if (!$reply) {
            error_log('[flarum-zai-bot] GenerateReplyForMessage: generateReply returned null. message_id=' . $message->id);
            return;
        }

        if ($author && $userId) {
            try {
                $memoryService = resolve(MemoryService::class);
                if ($memoryService->isAvailable()) {
                    $embedding = $memoryService->generateEmbedding($message->content . "\n" . strip_tags($reply));
                    if ($embedding) {
                        $memoryService->storeMemory($userId, "私信对话：{$message->content}\nAI回复：" . strip_tags($reply), $embedding);
                    }
                }
            } catch (\Exception $e) {
            }
        }

        $botMessage = new DialogMessage();
        $botMessage->dialog_id = $dialog->id;
        $botMessage->user_id = $botUser->id;
        // 显式传入 bot 作为格式化 actor，与论坛 Job 的 setContentAttribute($reply, $botUser)
        // 保持一致，避免 content 赋值时触发 $this->user 的额外查询且 actor 为 null。
        $botMessage->setContentAttribute($reply, $botUser);
        $botMessage->save();

        $botMessage->refresh();

        $dialog->setLastMessage($botMessage);
        $dialog->save();

        // 触发 Created 事件：让 flarum/realtime（Warble）实时推送机器人的私信，
        // 并让其他监听该事件的扩展（通知、统计等）感知机器人的回复。
        // 我们自己的 ReplyToMessage 监听器会识别出这是机器人的消息而不会再次派发任务。
        // 消息已持久化，任何同步监听器的异常都不应让任务失败重试（否则会重复生成回复）。
        try {
            $events->dispatch(new Created($botMessage));
        } catch (\Throwable $e) {
            error_log('[flarum-zai-bot] GenerateReplyForMessage: Created event dispatch failed: ' . $e->getMessage());
        }
    }
}
