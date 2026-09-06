<?php

namespace Zephyrisle\FlarumZaiBot\Job;

use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Zephyrisle\FlarumZaiBot\Job\Concerns\BuildsBotTools;
use Zephyrisle\FlarumZaiBot\Job\Concerns\ManagesBotUser;
use Zephyrisle\FlarumZaiBot\Model\BotAffinity;
use Zephyrisle\FlarumZaiBot\Service\AIService;
use Zephyrisle\FlarumZaiBot\Service\MediaExtractor;
use Zephyrisle\FlarumZaiBot\Service\Media\FileParsingService;
use Zephyrisle\FlarumZaiBot\Service\Media\LinkParsingService;
use Zephyrisle\FlarumZaiBot\Service\MemoryService;
use Zephyrisle\FlarumZaiBot\Service\PortraitService;
use Zephyrisle\FlarumZaiBot\Service\StreakService;
use Zephyrisle\FlarumZaiBot\Service\Text\PastePlaceholder;

/**
 * ramon/chat 聊天频道回复任务。
 *
 * 由 ReplyToChatMessage 监听器在 MessageWasSent 事件后异步派发：以 zai-bot
 * 账号（机器人用户）身份回复任意类型的聊天频道（直聊/群组/文字频道）中的人类消息。
 *
 * 兼容性前提：ramon/chat 未安装时（任务积压或扩展被移除）静默跳过；所有对
 * Ramon\Chat 空间的引用都在运行时存在性检查之后才执行。
 */
class GenerateReplyForChatMessage extends AbstractJob
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
        if (!class_exists(\Ramon\Chat\Message::class)
            || !class_exists(\Ramon\Chat\Event\MessageWasSent::class)
            || !class_exists(\Ramon\Chat\Service\MessageDispatcher::class)) {
            return;
        }

        $message = \Ramon\Chat\Message::find($this->messageId);

        if (!$message || !$message->channel) {
            return;
        }

        // 系统消息/机器人消息不回复（仅普通文本消息由人类用户发送）
        if ($message->type !== \Ramon\Chat\Message::TYPE_TEXT || $message->user_id === null) {
            return;
        }

        $channel = $message->channel;

        if (!(bool) $settings->get('flarum-zai-bot.chat_reply_enabled', false)) {
            return;
        }

        $botUsername = $settings->get('flarum-zai-bot.username', 'AIGirl');
        $botUser = $this->getBotUser($botUsername);

        // 机器人自己发出的消息（含兜底直接写入派发的 MessageWasSent）不二次触发
        if ((int) $message->user_id === (int) $botUser->id) {
            return;
        }

        // 只在机器人是成员的频道回复（直聊/群组/文字频道均可），保证回复对频道内用户可见
        if ($channel->membershipFor($botUser) === null) {
            return;
        }

        $author = $message->user;

        // flarum/suspend: 已封禁用户不触发回复
        if ($this->authorIsSuspended($author)) {
            error_log('[flarum-zai-bot] GenerateReplyForChatMessage: user suspended, skip. user_id=' . $author->id);
            return;
        }

        // 对话对象：直聊频道取另一位参与者；一般频道取消息作者
        $targetUser = $author;
        if ($channel->isDirect()) {
            $other = $channel->participants()->whereKeyNot($botUser->id)->first();
            if ($other) {
                $targetUser = $other;
            }
        }
        $userId = $targetUser ? (int) $targetUser->id : null;

        // ===== 对话历史：本频道该条消息之前的近期人类消息 =====
        $history = [];
        $historyImages = [];
        $recentMessages = \Ramon\Chat\Message::query()
            ->where('channel_id', $channel->id)
            ->where('id', '<', $message->id)
            ->orderBy('id', 'desc')
            ->take(10)
            ->get()
            ->reverse();

        foreach ($recentMessages as $prevMsg) {
            if ($prevMsg->type !== \Ramon\Chat\Message::TYPE_TEXT
                || $prevMsg->user_id === null
                || (int) $prevMsg->user_id === (int) $botUser->id) {
                continue;
            }

            $prevAuthor = $prevMsg->user;
            $authorName = $prevAuthor ? $prevAuthor->display_name : '未知';
            $history[] = [
                'author' => $authorName,
                'content' => PastePlaceholder::normalize((string) $prevMsg->content),
            ];

            // 历史消息中的图片，供支持识图的模型参考（AIService 按最近的优先截取）
            foreach (MediaExtractor::fromHtml($prevMsg->formatContent(), 1) as $imgUrl) {
                $historyImages[] = [
                    'url' => $imgUrl,
                    'author' => $authorName,
                    'label' => "聊天消息 #{$prevMsg->id}（{$authorName}）",
                ];
            }
        }

        $affinity = null;
        $portraitSummary = null;
        $memories = [];

        if ($targetUser) {
            $affinity = BotAffinity::getOrCreate($targetUser->id);

            // 黑名单熔断：好感度过低被拉黑时不再触发任何 LLM 思考
            if ($affinity->blacklisted) {
                error_log('[flarum-zai-bot] GenerateReplyForChatMessage: user blacklisted, skip. user_id=' . $targetUser->id);
                return;
            }

            try {
                $portraitSummary = resolve(PortraitService::class)->getPortraitSummary($targetUser->id);
            } catch (\Exception $e) {
            }

            try {
                $memoryService = resolve(MemoryService::class);
                if ($memoryService->isAvailable()) {
                    $embedding = $memoryService->generateEmbedding(PastePlaceholder::normalize((string) $message->content));
                    if ($embedding) {
                        $memories = $memoryService->searchMemories($targetUser->id, $embedding, 5, PastePlaceholder::normalize((string) $message->content));
                    }
                }
            } catch (\Exception $e) {
            }
        }

        $context = [
            'channel' => 'chat',
            'user_id' => $userId,
            'username' => $targetUser ? $targetUser->username : 'unknown',
            'display_name' => $targetUser ? $targetUser->display_name : '未知',
            'affinity_score' => $affinity?->total_score ?? null,
            'affinity_trust' => $affinity?->trust ?? null,
            'affinity_intimacy' => $affinity?->intimacy ?? null,
            'affinity_emotions' => $affinity?->emotions ?? null,
            'affinity_attitude' => $affinity?->attitude ?? null,
            'affinity_relationship' => $affinity?->relationship ?? null,
            'portrait_summary' => $portraitSummary,
            'memories' => $memories,
            'conversation_history' => $history,
            // 当前聊天消息中的多模态媒体（http(s)/data URI），供支持多模态的模型查看
            'images' => MediaExtractor::fromHtml($message->formatContent()),
            'videos' => MediaExtractor::videosFromHtml($message->formatContent()),
            'audios' => MediaExtractor::audiosFromHtml($message->formatContent()),
            'history_images' => $historyImages,
            // 频道信息
            'discussion_title' => $channel->name ?: ('聊天频道 #' . $channel->id),
        ];

        if ($targetUser) {
            $context['joined_at'] = $targetUser->joined_at ? $targetUser->joined_at->format('Y-m-d H:i:s') : null;
            $context['post_count'] = $targetUser->posts()->count();
            $context['group_names'] = $targetUser->groups->pluck('name_singular')->implode(', ') ?: null;

            if (class_exists(\Flarum\Nicknames\NicknameDriver::class) && !empty($targetUser->nickname)) {
                $context['display_name'] = $targetUser->nickname;
            }

            if (class_exists(\FoF\UserBio\Event\BioChanged::class) && $targetUser->bio) {
                $context['bio'] = strip_tags($targetUser->bio);
            }
        }

        // 纯媒体消息（只有图片/视频/音频没有文字）时给出文本锚点
        $messageContent = PastePlaceholder::normalize((string) $message->content);
        $plain = trim(strip_tags($messageContent));
        $hasMedia = !empty(MediaExtractor::fromHtml($message->formatContent()))
            || !empty(MediaExtractor::videosFromHtml($message->formatContent()))
            || !empty(MediaExtractor::audiosFromHtml($message->formatContent()));
        if ($plain !== '') {
            $prompt = $messageContent;
        } elseif ($hasMedia) {
            $prompt = '（用户发送了一条纯媒体消息，请查看后回应）';
        } else {
            $prompt = $messageContent;
        }

        // ===== 媒体解析：链接摘要与文件信息注入 =====
        $context['media_context'] = $this->buildMediaContext($message->formatContent(), $settings);

        // ===== 上下文注入：场景/身份环境字段 =====
        try {
            $ctxInjection = resolve(\Zephyrisle\FlarumZaiBot\Service\Context\ContextInjectionService::class);
            $context['injected_context'] = $ctxInjection->buildInjectedContext([
                'channel' => 'chat',
                'wake_type' => null,
                'discussion_id' => null,
                'discussion_title' => $channel->name ?: null,
                'user_id' => $userId,
                'username' => $targetUser ? $targetUser->username : null,
                'display_name' => $targetUser ? $targetUser->display_name : null,
                'group_names' => $context['group_names'] ?? null,
            ]);
        } catch (\Exception $e) {
            $context['injected_context'] = null;
        }

        $tools = $this->buildBotTools($botUser->id, $userId, $settings, 'private_message');

        // ===== 关系网、表达风格库与火花注入 =====
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

            // cloudnest「续火花」：直聊频道可读入双方火花状态（无 dialog_id 时按参与者对匹配）
            if ($userId) {
                $streak = resolve(StreakService::class)->readForPair($botUser->id, $userId);
                if ($streak !== null) {
                    $context['streak'] = $streak;
                }
            }
        } catch (\Exception $e) {
            error_log('[flarum-zai-bot] GenerateReplyForChatMessage: relation/expression/streak context failed: ' . $e->getMessage());
        }

        $reply = $ai->generateReply($prompt, $context, $tools);

        if ($reply && $userId) {
            $reply = $ai->parseSecretEval($reply, $userId);
        }

        // 兜底：清除回复中残留的粘贴占位符
        if ($reply) {
            $reply = PastePlaceholder::scrubReply($reply);
        }

        if (!$reply) {
            error_log('[flarum-zai-bot] GenerateReplyForChatMessage: generateReply returned null. message_id=' . $message->id);
            return;
        }

        if (!$channel->acceptsMessages()) {
            return;
        }

        // 频道最大消息长度钳制，避免被 ramon-chat 的 content 校验拒绝
        $maxLen = $channel->maxMessageLength(3000);
        if ($maxLen > 0 && mb_strlen($reply) > $maxLen) {
            $reply = mb_substr($reply, 0, $maxLen);
        }

        // ===== 发送回复 =====
        // 优先走 ramon/chat 官方派发路径（校验/限流/慢速模式/提及同步/未读计数/事件派发）。
        // 若因频道限制（慢速模式、限流、内容校验）被拒，兜底为最小化直接写入，
        // 保证机器人仍能回复、且不因派发器异常导致队列任务反复重试。
        try {
            resolve(\Ramon\Chat\Service\MessageDispatcher::class)
                ->send($channel, $botUser, $reply);
        } catch (\Throwable $e) {
            error_log('[flarum-zai-bot] GenerateReplyForChatMessage: MessageDispatcher send failed, fallback direct: ' . $e->getMessage());
            try {
                $botMessage = \Ramon\Chat\Message::build($channel, $botUser, $reply);
                $botMessage->save();
                $botMessage->refresh();
                $channel->refreshMetadata()->save();
                $events->dispatch(new \Ramon\Chat\Event\MessageWasSent($botMessage, $botUser));
            } catch (\Throwable $e2) {
                error_log('[flarum-zai-bot] GenerateReplyForChatMessage: fallback send failed: ' . $e2->getMessage());
                return;
            }
        }

        // ===== 记忆归档（异步不影响发送结果） =====
        if ($targetUser && $userId) {
            try {
                $memoryService = resolve(MemoryService::class);
                if ($memoryService->isAvailable()) {
                    $embedding = $memoryService->generateEmbedding($messageContent . "\n" . strip_tags($reply));
                    if ($embedding) {
                        $memoryService->storeMemory($userId, "聊天频道消息：{$messageContent}\nAI回复：" . strip_tags($reply), $embedding, [
                            'source_text' => mb_substr($messageContent, 0, 500),
                            'source_meta' => json_encode([
                                'type' => 'chat',
                                'channel_id' => $channel->id,
                                'message_id' => $message->id,
                            ], JSON_UNESCAPED_UNICODE),
                        ]);
                    }
                }
            } catch (\Exception $e) {
            }
        }
    }

    /**
     * 用户是否处于封禁期（flarum/suspend）。
     */
    protected function authorIsSuspended(?\Flarum\User\User $author): bool
    {
        if (!$author) {
            return false;
        }

        $until = $author->suspended_until ?? null;

        if ($until instanceof \DateTimeInterface) {
            return $until->getTimestamp() > time();
        }

        if (is_string($until) && $until !== '') {
            $ts = strtotime($until);

            return $ts !== false && $ts > time();
        }

        return false;
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