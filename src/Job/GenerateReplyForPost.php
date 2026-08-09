<?php

namespace Zephyrisle\FlarumZaiBot\Job;

use Carbon\Carbon;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Posted;
use Flarum\Post\Post;
use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Zephyrisle\FlarumZaiBot\Job\Concerns\BuildsBotTools;
use Zephyrisle\FlarumZaiBot\Job\Concerns\ManagesBotUser;
use Zephyrisle\FlarumZaiBot\Model\BotAffinity;
use Zephyrisle\FlarumZaiBot\Service\AIService;
use Zephyrisle\FlarumZaiBot\Service\MemoryService;
use Zephyrisle\FlarumZaiBot\Service\PortraitService;

class GenerateReplyForPost extends AbstractJob
{
    use BuildsBotTools;
    use ManagesBotUser;

    /**
     * 极短的固定防重保险窗口（秒）：仅用于防止队列并发/任务重试导致在同一时刻双发，
     * 与 AI 的自主回复决策无关。
     */
    private const RACE_GUARD_SECONDS = 10;

    public int $postId;

    public function __construct(int $postId)
    {
        $this->postId = $postId;
    }

    public function handle(AIService $ai, SettingsRepositoryInterface $settings, Dispatcher $events): void
    {
        $post = Post::find($this->postId);

        if (!$post || !$post->discussion) {
            return;
        }

        // 只对普通评论帖子回复，跳过置顶、改名、删除等系统帖子
        if ($post->type !== 'comment') {
            return;
        }

        $discussion = $post->discussion;

        $botUsername = $settings->get('flarum-zai-bot.username', 'AIGirl');
        $randomChance = $this->getRandomReplyChance($settings);

        $botUser = $this->getBotUser($botUsername);

        if ($post->user_id === $botUser->id) {
            return;
        }

        $content = $post->content;
        $shouldReply = false;

        if (preg_match('/@' . preg_quote($botUsername, '/') . '\b/i', $content)) {
            $shouldReply = true;
        }

        if (!$shouldReply && $randomChance > 0 && random_int(1, 100) <= $randomChance) {
            $shouldReply = true;
        }

        if (!$shouldReply) {
            return;
        }

        // 查询机器人在该讨论中的最近一次回复（同时用于防重保险与自主决策上下文）
        $lastBotReply = $this->getLastBotReply($discussion->id, $botUser->id);

        // 极短的固定防重保险窗口：防止队列并发/任务重试在同一时刻双发（与 AI 自主决策无关）
        if ($lastBotReply && $lastBotReply->created_at
            && (int) Carbon::now()->diffInSeconds($lastBotReply->created_at) < self::RACE_GUARD_SECONDS) {
            return;
        }

        $author = $post->user;
        $isVerified = false;
        if ($author && class_exists(\Ramon\Verified\TierResolver::class)) {
            $resolver = resolve(\Ramon\Verified\TierResolver::class);
            $isVerified = $resolver->isVerified($author);
        }

        $history = [];
        $recentPosts = Post::where('discussion_id', $discussion->id)
            ->where('id', '<', $post->id)
            ->where('type', 'comment')
            ->orderBy('id', 'desc')
            ->take(10)
            ->get()
            ->reverse();

        foreach ($recentPosts as $prevPost) {
            $prevAuthor = $prevPost->user;
            $history[] = [
                'post_id' => $prevPost->id,
                'author' => $prevAuthor ? $prevAuthor->display_name : '未知',
                'content' => strip_tags($prevPost->content),
            ];
        }

        // 计算“最近回复”上下文：如果机器人在 reply_cooldown 窗口内刚回复过，
        // 交由 AI 结合上下文自主决定是否再次回复（不再用固定时间强制跳过）。
        $cooldownSeconds = $this->getReplyCooldownSeconds($settings);
        $repliedRecently = false;
        $repliedRecentlySecondsAgo = 0;
        $lastBotReplyExcerpt = '';
        if ($cooldownSeconds > 0 && $lastBotReply && $lastBotReply->created_at) {
            // 钳制为 0，防止时钟偏移导致出现“-5秒前”这类异常文案
            $repliedRecentlySecondsAgo = max(0, (int) Carbon::now()->diffInSeconds($lastBotReply->created_at));
            if ($repliedRecentlySecondsAgo <= $cooldownSeconds) {
                $repliedRecently = true;
                $lastBotReplyExcerpt = mb_substr(strip_tags((string) $lastBotReply->content), 0, 200);
            }
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
                    $embedding = $memoryService->generateEmbedding($content);
                    if ($embedding) {
                        $memories = $memoryService->searchMemories($author->id, $embedding, 5);
                    }
                }
            } catch (\Exception $e) {
            }
        }

        $context = [
            'channel' => 'forum',
            'user_id' => $userId,
            'current_post_id' => $post->id,
            'discussion_title' => $discussion->title ?? 'Untitled',
            'username' => $author ? $author->username : 'unknown',
            'display_name' => $author ? $author->display_name : '未知',
            'is_verified' => $isVerified,
            'affinity_score' => $affinity?->total_score ?? null,
            'portrait_summary' => $portraitSummary,
            'memories' => $memories,
            'conversation_history' => $history,
            'replied_recently' => $repliedRecently,
            'replied_recently_seconds_ago' => $repliedRecentlySecondsAgo,
            'last_bot_reply_excerpt' => $lastBotReplyExcerpt,
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

        $reply = $ai->generateReply($content, $context, $tools);

        if ($reply && $userId) {
            // 先解析秘密评估：即使 AI 决定保持沉默，也允许记录观察/调整好感度
            $reply = $ai->parseSecretEval($reply, $userId);
        }

        // AI 自主决定保持沉默（回复中包含跳过标记）。
        // 注意：标记检测为包含匹配——宁可误吞一条回复，也不把标记泄漏给用户。
        if ($reply && $this->shouldSkipReply($reply)) {
            error_log('[flarum-zai-bot] GenerateReplyForPost: AI decided to stay silent. post_id=' . $post->id . ' discussion_id=' . $discussion->id);
            return;
        }

        if (!$reply) {
            error_log('[flarum-zai-bot] GenerateReplyForPost: generateReply returned null. post_id=' . $post->id . ' discussion_id=' . $discussion->id);
            return;
        }

        if ($author && $userId) {
            try {
                $memoryService = resolve(MemoryService::class);
                if ($memoryService->isAvailable()) {
                    $embedding = $memoryService->generateEmbedding(strip_tags($reply));
                    if ($embedding) {
                        $memoryService->storeMemory($userId, "用户：{$context['display_name']} 在讨论「{$discussion->title}」中发帖：" . strip_tags($content) . "\nAI回复：" . strip_tags($reply), $embedding);
                    }
                }
            } catch (\Exception $e) {
            }
        }

        $botPost = new CommentPost();
        $botPost->discussion_id = $post->discussion_id;
        $botPost->user_id = $botUser->id;
        $botPost->created_at = Carbon::now();
        $botPost->setContentAttribute($reply, $botUser);
        $botPost->save();

        // 帖子已持久化，任何同步监听器（如 realtime 推送）的异常/错误都不应让任务失败重试，
        // 否则重试会重新生成回复并产生重复帖子。
        try {
            $events->dispatch(new Posted($botPost));
        } catch (\Throwable $e) {
            error_log('[flarum-zai-bot] GenerateReplyForPost: Posted event dispatch failed: ' . $e->getMessage());
        }
    }

    /**
     * 最近回复判定窗口（秒）。窗口内 AI 会收到“最近回复”上下文并自主决定是否再次回复。
     * 0 表示不提供该上下文（总是回复）。
     */
    protected function getReplyCooldownSeconds(SettingsRepositoryInterface $settings): int
    {
        return max(0, (int) $settings->get('flarum-zai-bot.reply_cooldown', 30));
    }

    /**
     * 随机回复概率（%），钳制在 0-100 之间，防止配置异常导致每次都回复。
     */
    protected function getRandomReplyChance(SettingsRepositoryInterface $settings): int
    {
        return max(0, min(100, (int) $settings->get('flarum-zai-bot.random_reply_chance', 0)));
    }

    /**
     * 判断 AI 是否通过跳过标记决定保持沉默。
     */
    protected function shouldSkipReply(string $reply): bool
    {
        return str_contains($reply, AIService::SKIP_MARKER);
    }

    protected function getLastBotReply(int $discussionId, int $botUserId): ?CommentPost
    {
        return CommentPost::where('discussion_id', $discussionId)
            ->where('user_id', $botUserId)
            ->orderBy('id', 'desc')
            ->first();
    }
}
