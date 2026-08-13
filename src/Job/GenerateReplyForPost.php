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
use Zephyrisle\FlarumZaiBot\Service\ImageExtractor;
use Zephyrisle\FlarumZaiBot\Service\Media\FileParsingService;
use Zephyrisle\FlarumZaiBot\Service\Media\LinkParsingService;
use Zephyrisle\FlarumZaiBot\Service\MemoryService;
use Zephyrisle\FlarumZaiBot\Service\PortraitService;
use Zephyrisle\FlarumZaiBot\Service\Wake\WakeService;

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

        // 最终校验：帖子已被隐藏/撤回 → 取消
        if ($post->hidden_at) {
            return;
        }

        $discussion = $post->discussion;

        $botUsername = $settings->get('flarum-zai-bot.username', 'AIGirl');
        $randomChance = $this->getRandomReplyChance($settings);
        $mergeSeconds = $this->getMergeSeconds($settings);
        $mergeMax = $this->getMergeMax($settings);
        $requireWake = (bool) $settings->get('flarum-zai-bot.wake_merge_require_wake', false);

        $botUser = $this->getBotUser($botUsername);

        if ($post->user_id === $botUser->id) {
            return;
        }

        $author = $post->user;
        $userId = $author ? $author->id : null;

        // 查询机器人在该讨论中的最近一次回复（用于防重保险、合并去重与自主决策上下文）
        $lastBotReply = $this->getLastBotReply($discussion->id, $botUser->id);

        // 极短的固定防重保险窗口：防止队列并发/任务重试在同一时刻双发（与 AI 自主决策无关）
        if ($lastBotReply && $lastBotReply->created_at
            && (int) Carbon::now()->diffInSeconds($lastBotReply->created_at) < self::RACE_GUARD_SECONDS) {
            return;
        }

        $secondsSinceLastBotReply = null;
        if ($lastBotReply && $lastBotReply->created_at) {
            $secondsSinceLastBotReply = max(0, (int) Carbon::now()->diffInSeconds($lastBotReply->created_at));
        }

        $content = $post->content;
        $plainContent = trim(strip_tags((string) $content));

        // 纯媒体消息（只有图片没有文字）时给出文本锚点，方便模型理解
        if ($plainContent === '' && !empty(ImageExtractor::fromHtml($content))) {
            $plainContent = '（用户发布了一条纯媒体消息，请查看后回应）';
        }

        $history = [];
        $historyImages = [];
        $recentPosts = Post::where('discussion_id', $discussion->id)
            ->where('id', '<', $post->id)
            ->where('type', 'comment')
            ->orderBy('id', 'desc')
            ->take(10)
            ->get()
            ->reverse();

        foreach ($recentPosts as $prevPost) {
            $prevAuthor = $prevPost->user;
            $postId = $prevPost->id;
            $authorName = $prevAuthor ? $prevAuthor->display_name : '未知';
            $history[] = [
                'post_id' => $postId,
                'author' => $authorName,
                'content' => strip_tags($prevPost->content),
            ];

            // 历史帖子中的图片，供支持识图的模型参考（AIService 会按最近的优先截取）
            foreach (ImageExtractor::fromHtml($prevPost->content, 1) as $imgUrl) {
                $historyImages[] = [
                    'url' => $imgUrl,
                    'author' => $authorName,
                    'label' => "帖子 #{$postId}（{$authorName}）",
                ];
            }
        }

        $wake = resolve(WakeService::class);

        // ===== 请求编排：硬等待消息合并 =====
        // 合并窗口去重：若该触发帖之后已有机器人回复（由更早的合并任务发出），跳过
        $mergedPosts = [];
        $lastMergedId = $post->id;
        if ($mergeSeconds > 0) {
            $coveringReply = CommentPost::where('discussion_id', $discussion->id)
                ->where('user_id', $botUser->id)
                ->where('id', '>', $post->id)
                ->orderBy('id')
                ->first();

            if ($coveringReply) {
                error_log('[flarum-zai-bot] GenerateReplyForPost: covering reply exists, skip. post_id=' . $post->id);
                return;
            }

            // 收集合并窗口内的后续帖子（过滤机器人帖子与隐藏帖子）
            $mergedPosts = $this->collectMergePosts(
                $post,
                $discussion->id,
                $botUser->id,
                $mergeSeconds,
                $mergeMax,
                $requireWake,
                $wake,
                $randomChance,
                $botUsername,
                $secondsSinceLastBotReply,
                $history
            );
        }

        // ===== 智能唤醒：触发判定 =====
        $decision = $wake->detect(
            $plainContent,
            $discussion->id,
            $userId,
            $history,
            $randomChance,
            $botUsername,
            $secondsSinceLastBotReply,
            $this->repliesToOtherPost($content),
            count($history)
        );

        $shouldReply = $decision->reply;

        // 后续消息唤醒要求：合并窗口内的新消息也需满足唤醒条件
        // （collectMergePosts 已按唤醒条件过滤，存在即说明窗口内有消息满足唤醒）
        if (!$shouldReply && $mergeSeconds > 0 && $requireWake && !empty($mergedPosts)) {
            $shouldReply = true;
        }

        if (!$shouldReply) {
            error_log('[flarum-zai-bot] GenerateReplyForPost: no wake trigger. post_id=' . $post->id
                . ' type=' . ($decision->type ?? 'none') . ' score=' . $decision->score . ' reason=' . $decision->reason);
            return;
        }

        // ===== 消息合并：构建整体提示词与图片列表 =====
        $prompt = $plainContent;
        $images = ImageExtractor::fromHtml($content);
        if ($mergeSeconds > 0 && !empty($mergedPosts)) {
            $prompt = $this->buildMergedPrompt($post, $plainContent, $mergedPosts);
            foreach ($mergedPosts as $mergedPost) {
                foreach (ImageExtractor::fromHtml((string) $mergedPost->content, 2) as $imgUrl) {
                    $images[] = $imgUrl;
                }
            }
            $lastMergedId = end($mergedPosts)->id;
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
                    $embedding = $memoryService->generateEmbedding($prompt);
                    if ($embedding) {
                        // 混合检索：向量 + BM25 关键词双路召回（query 供关键词路使用）
                        $memories = $memoryService->searchMemories($author->id, $embedding, 5, $prompt);
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
            'is_verified' => $this->isVerified($author),
            'affinity_score' => $affinity?->total_score ?? null,
            'portrait_summary' => $portraitSummary,
            'memories' => $memories,
            'conversation_history' => $history,
            'replied_recently' => $repliedRecently,
            'replied_recently_seconds_ago' => $repliedRecentlySecondsAgo,
            'last_bot_reply_excerpt' => $lastBotReplyExcerpt,
            // 当前消息（含合并窗口内帖子）中的图片，供支持识图的模型查看（见 AIService）
            'images' => $images,
            // 对话历史帖子中的图片，让模型能结合更早的图片回答
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

        // ===== 媒体解析：链接摘要与文件信息注入 =====
        $context['media_context'] = $this->buildMediaContext($content, $settings);

        // ===== 上下文注入：场景/身份环境字段 + 讨论近期事件 =====
        // 注入时机（proactive/all/off）、格式（concise/detailed）与截断由设置控制（见 ContextInjectionService）
        try {
            $ctxInjection = resolve(\Zephyrisle\FlarumZaiBot\Service\Context\ContextInjectionService::class);
            $context['injected_context'] = $ctxInjection->buildInjectedContext([
                'channel' => 'forum',
                'wake_type' => $decision->type,
                'discussion_id' => $discussion->id,
                'discussion_title' => $discussion->title ?? null,
                'user_id' => $userId,
                'username' => $author ? $author->username : null,
                'display_name' => $author ? $author->display_name : null,
                'group_names' => $context['group_names'] ?? null,
            ]);
        } catch (\Exception $e) {
            $context['injected_context'] = null;
        }

        $tools = $this->buildBotTools($botUser->id, $userId, $settings);

        $reply = $ai->generateReply($prompt, $context, $tools);

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

        // ===== 请求编排：发帖前最终校验与并发控制 =====
        if ($mergeSeconds > 0) {
            // 动态软重算：处理期间出现了更新的帖子 → 放弃本轮结果，由新帖子的任务重算上下文
            $newerExists = Post::where('discussion_id', $discussion->id)
                ->where('type', 'comment')
                ->where('id', '>', $lastMergedId)
                ->where('user_id', '!=', $botUser->id)
                ->exists();

            if ($newerExists) {
                error_log('[flarum-zai-bot] GenerateReplyForPost: recompute skip (newer posts). post_id=' . $post->id);
                return;
            }

            // 并发控制：处理期间已有覆盖本触发帖的机器人回复 → 跳过（防止双发）
            $coveringReply = CommentPost::where('discussion_id', $discussion->id)
                ->where('user_id', $botUser->id)
                ->where('id', '>', $post->id)
                ->exists();

            if ($coveringReply) {
                error_log('[flarum-zai-bot] GenerateReplyForPost: covering reply appeared, skip. post_id=' . $post->id);
                return;
            }
        }

        if ($author && $userId) {
            try {
                $memoryService = resolve(MemoryService::class);
                if ($memoryService->isAvailable()) {
                    $embedding = $memoryService->generateEmbedding(strip_tags($reply));
                    if ($embedding) {
                        // 原文与归档：保留来源消息原文与来源元信息（讨论/帖子），便于核验与重新总结
                        $memoryService->storeMemory($userId, "用户：{$context['display_name']} 在讨论「{$discussion->title}」中发帖：{$prompt}\nAI回复：" . strip_tags($reply), $embedding, [
                            'source_text' => mb_substr(strip_tags((string) $post->content), 0, 500),
                            'source_meta' => json_encode([
                                'type' => 'discussion_post',
                                'discussion_id' => $discussion->id,
                                'post_id' => $post->id,
                                'discussion_title' => $discussion->title ?? null,
                            ], JSON_UNESCAPED_UNICODE),
                        ]);
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
                    $parts[] = "帖子中链接的内容摘要：\n" . implode("\n", $lines);
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
                    $parts[] = "帖子中引用的文件：\n" . implode("\n", $lines);
                }
            } catch (\Exception $e) {
            }
        }

        return $parts !== [] ? implode("\n\n", $parts) : null;
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
     * 硬等待合并窗口（秒）：>0 时先收集窗口内的后续帖子再统一发起请求。
     */
    protected function getMergeSeconds(SettingsRepositoryInterface $settings): int
    {
        return max(0, (int) $settings->get('flarum-zai-bot.wake_merge_seconds', 0));
    }

    /**
     * 合并数量上限（条），钳制在 1-20。
     */
    protected function getMergeMax(SettingsRepositoryInterface $settings): int
    {
        return max(1, min(20, (int) $settings->get('flarum-zai-bot.wake_merge_max', 5)));
    }

    /**
     * 收集合并窗口内的帖子（不含触发帖），已过滤机器人帖子与隐藏（撤回）帖子。
     * wake_merge_require_wake 开启时，窗口内的新消息也需满足唤醒条件才会被并入。
     *
     * @return CommentPost[]
     */
    protected function collectMergePosts(
        Post $trigger,
        int $discussionId,
        int $botUserId,
        int $mergeSeconds,
        int $mergeMax,
        bool $requireWake,
        WakeService $wake,
        int $randomChance,
        string $botUsername,
        ?int $secondsSinceLastBotReply,
        array $history
    ): array {
        $cutoff = $trigger->created_at ? $trigger->created_at->copy()->addSeconds($mergeSeconds) : null;

        $candidates = Post::where('discussion_id', $discussionId)
            ->where('type', 'comment')
            ->where('id', '>', $trigger->id)
            ->where('user_id', '!=', $botUserId)
            ->when($cutoff, fn ($q) => $q->where('created_at', '<=', $cutoff))
            ->orderBy('id')
            ->take($mergeMax)
            ->get();

        $merged = [];
        foreach ($candidates as $candidate) {
            // 最终校验：已隐藏/撤回的帖子直接剔除
            if ($candidate->hidden_at) {
                continue;
            }

            // 后续消息唤醒要求：新消息也需满足唤醒条件
            if ($requireWake && !$wake->detect(
                trim(strip_tags((string) $candidate->content)),
                $discussionId,
                $candidate->user_id,
                $history,
                $randomChance,
                $botUsername,
                $secondsSinceLastBotReply,
                $this->repliesToOtherPost((string) $candidate->content),
                count($history)
            )->reply) {
                continue;
            }

            $merged[] = $candidate;
        }

        return $merged;
    }

    /**
     * 把触发帖与合并窗口内的帖子拼成整体提示词。
     *
     * @param CommentPost[] $mergedPosts
     */
    protected function buildMergedPrompt(Post $trigger, string $triggerText, array $mergedPosts): string
    {
        $lines = [];
        $triggerAuthor = $trigger->user ? $trigger->user->display_name : '未知';
        $lines[] = "帖子 #{$trigger->id} {$triggerAuthor}：" . trim($triggerText);

        foreach ($mergedPosts as $mp) {
            $authorName = $mp->user ? $mp->user->display_name : '未知';
            $lines[] = "帖子 #{$mp->id} {$authorName}：" . trim(strip_tags((string) $mp->content));
        }

        return "（以下为合并窗口内连续发送的消息，请整体理解后回复）\n\n" . implode("\n\n", $lines);
    }

    /**
     * 判断帖子是否回复/引用他人帖子（Flarum 的 [quote] 渲染为 blockquote）。
     */
    protected function repliesToOtherPost(string $contentHtml): bool
    {
        return str_contains($contentHtml, '<blockquote');
    }

    protected function isVerified($author): bool
    {
        if (!$author || !class_exists(\Ramon\Verified\TierResolver::class)) {
            return false;
        }

        try {
            $resolver = resolve(\Ramon\Verified\TierResolver::class);

            return $resolver->isVerified($author);
        } catch (\Exception $e) {
            return false;
        }
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
