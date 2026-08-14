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
use Zephyrisle\FlarumZaiBot\Service\ImageExtractor;
use Zephyrisle\FlarumZaiBot\Service\Media\FileParsingService;
use Zephyrisle\FlarumZaiBot\Service\Media\LinkParsingService;
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
        $historyImages = [];
        $recentMessages = DialogMessage::where('dialog_id', $dialog->id)
            ->where('id', '<', $message->id)
            ->orderBy('id', 'desc')
            ->take(10)
            ->get()
            ->reverse();

        foreach ($recentMessages as $prevMsg) {
            $prevAuthor = $prevMsg->user;
            $msgId = $prevMsg->id;
            $authorName = $prevAuthor ? $prevAuthor->display_name : '未知';
            $history[] = [
                'author' => $authorName,
                'content' => $prevMsg->content,
            ];

            // 历史私信中的图片，供支持识图的模型参考（AIService 会按最近的优先截取）
            foreach (ImageExtractor::fromHtml((string) $prevMsg->content, 1) as $imgUrl) {
                $historyImages[] = [
                    'url' => $imgUrl,
                    'author' => $authorName,
                    'label' => "私信消息 #{$msgId}（{$authorName}）",
                ];
            }
        }

        $affinity = null;
        $portraitSummary = null;
        $memories = [];
        $userId = $author ? $author->id : null;

        if ($author) {
            $affinity = BotAffinity::getOrCreate($author->id);

            // 黑名单熔断：好感度过低被自动（或管理员手动）拉黑时，不再触发任何 LLM 思考
            if ($affinity->blacklisted) {
                error_log('[flarum-zai-bot] GenerateReplyForMessage: user blacklisted, skip. user_id=' . $author->id);
                return;
            }

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
                        // 混合检索：向量 + BM25 关键词双路召回（query 供关键词路使用）
                        $memories = $memoryService->searchMemories($author->id, $embedding, 5, (string) $message->content);
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
            'affinity_trust' => $affinity?->trust ?? null,
            'affinity_intimacy' => $affinity?->intimacy ?? null,
            'affinity_emotions' => $affinity?->emotions ?? null,
            'affinity_attitude' => $affinity?->attitude ?? null,
            'affinity_relationship' => $affinity?->relationship ?? null,
            'portrait_summary' => $portraitSummary,
            'memories' => $memories,
            'conversation_history' => $history,
            // 当前私信中的图片（http(s)/data URI），供支持识图的模型查看（见 AIService）
            'images' => ImageExtractor::fromHtml((string) $message->content),
            // 对话历史私信中的图片，让模型能结合更早的图片回答
            'history_images' => $historyImages,
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

        // 纯媒体消息（只有图片没有文字）时给出文本锚点；私聊任何消息都会触发回复（媒体唤醒）
        $plain = trim(strip_tags((string) $message->content));
        $prompt = $plain !== '' ? $message->content : '（用户发送了一条纯媒体消息，请查看后回应）';

        // ===== 媒体解析：链接摘要与文件信息注入 =====
        $context['media_context'] = $this->buildMediaContext((string) $message->content, $settings);

        // ===== 上下文注入：场景/身份环境字段 + 讨论近期事件 =====
        // 私信无主动/被动之分，只要注入时机不是关闭即注入（见 ContextInjectionService）
        try {
            $ctxInjection = resolve(\Zephyrisle\FlarumZaiBot\Service\Context\ContextInjectionService::class);
            $context['injected_context'] = $ctxInjection->buildInjectedContext([
                'channel' => 'message',
                'wake_type' => null,
                'discussion_id' => null,
                'discussion_title' => null,
                'user_id' => $userId,
                'username' => $author ? $author->username : null,
                'display_name' => $author ? $author->display_name : null,
                'group_names' => $context['group_names'] ?? null,
            ]);
        } catch (\Exception $e) {
            $context['injected_context'] = null;
        }

        $tools = $this->buildBotTools($botUser->id, $userId, $settings, 'private_message');

        // ===== 关系网与表达风格库注入 =====
        try {
            if ((bool) $settings->get('flarum-zai-bot.relation_network_enabled', true) && $userId) {
                $context['relation_summary'] = resolve(\Zephyrisle\FlarumZaiBot\Service\RelationService::class)->buildSummary($userId);
            }

            if ((bool) $settings->get('flarum-zai-bot.expression_learning_enabled', true)) {
                $expressionService = resolve(\Zephyrisle\FlarumZaiBot\Service\ExpressionService::class);
                $activeRules = $expressionService->activeRules('private_message', $userId, null);
                if ($activeRules !== []) {
                    $context['expression_rules'] = $expressionService->buildInjectionText($activeRules);
                }
            }
        } catch (\Exception $e) {
            error_log('[flarum-zai-bot] GenerateReplyForMessage: relation/expression context failed: ' . $e->getMessage());
        }

        $reply = $ai->generateReply($prompt, $context, $tools);

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
                        // 原文与归档：保留来源消息原文与来源元信息（对话框/消息），便于核验
                        $memoryService->storeMemory($userId, "私信对话：{$message->content}\nAI回复：" . strip_tags($reply), $embedding, [
                            'source_text' => mb_substr(strip_tags((string) $message->content), 0, 500),
                            'source_meta' => json_encode([
                                'type' => 'private_message',
                                'dialog_id' => $dialog->id,
                                'message_id' => $message->id,
                            ], JSON_UNESCAPED_UNICODE),
                        ]);
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

    /**
     * 构建媒体解析上下文（链接摘要 + 文件信息），未启用或结果为空时返回 null。
     */
    protected function buildMediaContext(string $contentHtml, SettingsRepositoryInterface $settings): ?string
    {
        $parts = [];

        if ((bool) $settings->get('flarum-zai-bot.media_link_parse_enabled', false)) {
            try {
                $links = resolve(LinkParsingService::class)->parse($contentHtml);
                if (!empty($links)) {
                    $lines = [];
                    foreach ($links as $link) {
                        $title = $link['title'] !== '' ? $link['title'] : $link['url'];
                        $lines[] = '- ' . $title . ($link['summary'] !== '' ? '：' . $link['summary'] : '');
                    }
                    $parts[] = "消息中链接的内容摘要：\n" . implode("\n", $lines);
                }
            } catch (\Exception $e) {
            }
        }

        if ((bool) $settings->get('flarum-zai-bot.media_file_parse_enabled', false)) {
            try {
                $files = resolve(FileParsingService::class)->parse($contentHtml);
                if (!empty($files)) {
                    $lines = [];
                    foreach ($files as $file) {
                        $line = "- {$file['name']}（{$file['size']}）";
                        if ($file['preview'] !== '') {
                            $line .= "：{$file['preview']}";
                        }
                        $lines[] = $line;
                    }
                    $parts[] = "消息中引用的文件：\n" . implode("\n", $lines);
                }
            } catch (\Exception $e) {
            }
        }

        return $parts !== [] ? implode("\n\n", $parts) : null;
    }
}
