<?php

namespace Zephyrisle\FlarumZaiBot\Job;

use Carbon\Carbon;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Posted;
use Flarum\Post\Post;
use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
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

        // flarum/lock: 已锁定的讨论不再触发回复
        // （is_locked 是 flarum/lock 添加的数据库列，扩展未安装时该属性为 null，天然跳过）
        if (!empty($discussion->is_locked)) {
            error_log('[flarum-zai-bot] GenerateReplyForPost: discussion locked, skip. discussion_id=' . $discussion->id);
            return;
        }

        $botUsername = $settings->get('flarum-zai-bot.username', 'AIGirl');
        $randomChance = $this->getRandomReplyChance($settings);
        $mergeSeconds = $this->getMergeSeconds($settings);
        $mergeMax = $this->getMergeMax($settings);
        $requireWake = (bool) $settings->get('flarum-zai-bot.wake_merge_require_wake', false);

        // 挖坟检测（可配合 fof/prevent-necrobumping 使用）：老讨论突然来了新回复。
        // 结果在下方构建 $context 后写入，避免被整体赋值覆盖丢失。
        $isNecroBump = false;
        $necroDays = 0;
        try {
            $lastPostAt = $discussion->last_posted_at;
            if ($lastPostAt) {
                $necroDays = (int) $lastPostAt->diffInDays(now());
                if ($necroDays > 30) {
                    $isNecroBump = true;
                }
            }
        } catch (\Exception $e) {
        }

        $botUser = $this->getBotUser($botUsername);

        if ($post->user_id === $botUser->id) {
            return;
        }

        $author = $post->user;
        $userId = $author ? $author->id : null;

        // flarum/suspend: 已封禁用户不触发回复
        // （flarum/suspend 只有 suspended_until 列，没有 is_suspended 属性）
        if ($this->authorIsSuspended($author)) {
            error_log('[flarum-zai-bot] GenerateReplyForPost: user suspended, skip. user_id=' . $userId);
            return;
        }

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

        // $post->content 是 Formatter::unparse 后的 Markdown 源文（Flarum 2.x），
        // 图片提取 / 媒体解析 / 引用检测需要渲染后的 HTML，用 formatContent() 获取。
        $content = $post->content;
        $contentHtml = $post->formatContent();
        $plainContent = trim(strip_tags((string) $content));

        // 纯媒体消息（只有图片没有文字）时给出文本锚点，方便模型理解
        if ($plainContent === '' && !empty(ImageExtractor::fromHtml($contentHtml))) {
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
            foreach (ImageExtractor::fromHtml($prevPost->formatContent(), 1) as $imgUrl) {
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
            $this->repliesToOtherPost($contentHtml),
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
        $images = ImageExtractor::fromHtml($contentHtml);
        if ($mergeSeconds > 0 && !empty($mergedPosts)) {
            $prompt = $this->buildMergedPrompt($post, $plainContent, $mergedPosts);
            foreach ($mergedPosts as $mergedPost) {
                foreach (ImageExtractor::fromHtml($mergedPost->formatContent(), 2) as $imgUrl) {
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

            // 黑名单熔断：好感度过低被自动（或管理员手动）拉黑时，不再触发任何 LLM 思考
            if ($affinity->blacklisted) {
                error_log('[flarum-zai-bot] GenerateReplyForPost: user blacklisted, skip. user_id=' . $author->id);
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
            'affinity_trust' => $affinity?->trust ?? null,
            'affinity_intimacy' => $affinity?->intimacy ?? null,
            'affinity_emotions' => $affinity?->emotions ?? null,
            'affinity_attitude' => $affinity?->attitude ?? null,
            'affinity_relationship' => $affinity?->relationship ?? null,
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

        // 挖坟提醒：写入此处（$context 构建完成之后），确保不被上方整体赋值覆盖
        if ($isNecroBump) {
            $context['necro_bump'] = true;
            $context['necro_days'] = $necroDays;
        }

        // flarum/tags: 注入讨论标签信息
        if (class_exists(\Flarum\Tags\Tag) && method_exists($discussion, 'tags')) {
            try {
                $tags = $discussion->tags()->pluck('name')->filter()->values()->all();
                if (!empty($tags)) {
                    $context['discussion_tags'] = implode(', ', $tags);
                }
            } catch (\Exception $e) {
            }
        }

        // fof/byobu: 私有讨论检测与收件人信息
        if (class_exists(\FoF\Byobu\Listeners\IgnoreApprovals)) {
            try {
                if (!empty($discussion->isByobu)) {
                    $context['is_private_discussion'] = true;
                    $recipients = $discussion->recipientUsers()->pluck('display_name')->filter()->values()->all();
                    if (!empty($recipients)) {
                        $context['private_recipients'] = implode(', ', $recipients);
                    }
                    $recipientGroups = $discussion->recipientGroups()->pluck('name_singular')->filter()->values()->all();
                    if (!empty($recipientGroups)) {
                        $context['private_recipient_groups'] = implode(', ', $recipientGroups);
                    }
                }
            } catch (\Exception $e) {
            }
        }

        // fof/upload: 解析帖子中的文件附件信息
        if (class_exists(\FoF\Upload\File::class)) {
            try {
                $fofFiles = \FoF\Upload\File::where('post_id', $post->id)->get();
                if ($fofFiles->isNotEmpty()) {
                    $fileLines = [];
                    foreach ($fofFiles as $file) {
                        $line = "- {$file->name}";
                        if ($file->size) {
                            // fof/upload File 模型没有 formatSize()，大小格式化由本扩展自己完成
                            $line .= "（{$this->humanSize((int) $file->size)}）";
                        }
                        $fileLines[] = $line;
                    }
                    $context['file_attachments'] = implode("\n", $fileLines);
                }
            } catch (\Exception $e) {
            }
        }

        // fof/polls: 注入投票信息
        if (class_exists(\FoF\Polls\Poll)) {
            try {
                $poll = \FoF\Polls\Poll::where('discussion_id', $discussion->id)->first();
                if ($poll) {
                    $pollInfo = "投票：{$poll->question}\n";
                    $options = $poll->options()->get();
                    foreach ($options as $option) {
                        $pollInfo .= "- {$option->percentage}% {$option->text}\n";
                    }
                    $context['poll_info'] = trim($pollInfo);
                }
            } catch (\Exception $e) {
            }
        }

        // fof/pages: 注入关联页面内容（如果讨论与页面关联）
        if (class_exists(\FoF\Pages\Page)) {
            try {
                // 查找与当前讨论关联的页面（通过 discussion 的 slug 或关系）
                $page = \FoF\Pages\Page::where('discussion_id', $discussion->id)->first();
                if ($page && $page->content) {
                    $pageContent = mb_substr(strip_tags((string) $page->content), 0, 500);
                    $context['page_context'] = "关联页面「{$page->title}」：\n{$pageContent}";
                }
            } catch (\Exception $e) {
            }
        }

        // linkrobins/wiki: 注入 Wiki 文章内容（如果讨论标题匹配 Wiki 文章）
        if (class_exists(\LinkRobins\Wiki\WikiArticle)) {
            try {
                // 按讨论标题模糊搜索 Wiki 文章
                $wikiArticle = \LinkRobins\Wiki\WikiArticle::where('title', 'LIKE', '%' . mb_substr($discussion->title, 0, 20) . '%')
                    ->orWhere('slug', 'LIKE', '%' . mb_substr($discussion->title, 0, 20) . '%')
                    ->first();
                if ($wikiArticle && $wikiArticle->content) {
                    $wikiContent = mb_substr(strip_tags((string) $wikiArticle->content), 0, 500);
                    $context['wiki_context'] = "相关 Wiki 文章「{$wikiArticle->title}」：\n{$wikiContent}";
                }
            } catch (\Exception $e) {
            }
        }

        if ($author) {
            $context['joined_at'] = $author->joined_at ? $author->joined_at->format('Y-m-d H:i:s') : null;
            $context['post_count'] = $author->posts()->count();
            $context['group_names'] = $author->groups->pluck('name_singular')->implode(', ') ?: null;

            // flarum/nicknames: 优先使用昵称作为显示名
            if (class_exists(\Flarum\Nicknames\NicknameDriver) && !empty($author->nickname)) {
                $context['display_name'] = $author->nickname;
            }

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

            // fof/socialprofile: 注入用户社交资料
            if (class_exists(\FoF\SocialProfile\Listeners\SaveUserPreferences) && !empty($author->social_buttons)) {
                try {
                    $socialButtons = json_decode($author->social_buttons, true);
                    if (is_array($socialButtons) && !empty($socialButtons)) {
                        $socialLines = [];
                        foreach ($socialButtons as $button) {
                            $label = $button['label'] ?? ($button['type'] ?? '社交');
                            $url = $button['url'] ?? '';
                            if ($url !== '') {
                                $socialLines[] = "- {$label}: {$url}";
                            }
                        }
                        if (!empty($socialLines)) {
                            $context['social_profiles'] = implode("\n", $socialLines);
                        }
                    }
                } catch (\Exception $e) {
                }
            }
        }

        // fof/geoip: 注入发帖者地理位置信息
        if (class_exists(\FoF\GeoIP\Listeners\RetrieveIP)) {
            try {
                $ipInfo = $post->ip_info()->first();
                if ($ipInfo) {
                    $locationParts = [];
                    if (!empty($ipInfo->country)) {
                        $locationParts[] = $ipInfo->country;
                    }
                    if (!empty($ipInfo->city)) {
                        $locationParts[] = $ipInfo->city;
                    }
                    if (!empty($locationParts)) {
                        $context['user_location'] = implode('，', $locationParts);
                    }
                }
            } catch (\Exception $e) {
            }
        }

        // flarum/sticky: 检测置顶讨论（is_sticky 为 flarum/sticky 添加的列，未安装时为 null）
        if (!empty($discussion->is_sticky)) {
            $context['is_sticky'] = true;
        }

        // 讨论热度：comment_count 是 Flarum 核心列（回复数），超过阈值视为高热度
        $viewCount = (int) ($discussion->comment_count ?? 0);
        if ($viewCount > 100) {
            $context['discussion_popularity'] = 'high';
        } elseif ($viewCount > 50) {
            $context['discussion_popularity'] = 'medium';
        }

        // flarum/subscriptions: 检查当前用户订阅状态
        if (class_exists('\Flarum\Subscriptions\UserState') && $userId) {
            try {
                $subscription = \Flarum\Subscriptions\UserState::where('discussion_id', $discussion->id)
                    ->where('user_id', $userId)
                    ->first();
                if ($subscription) {
                    $context['user_subscription'] = $subscription->last_read_at ? 'following' : 'lurking';
                }
            } catch (\Exception $e) {
            }
        }

        // fof/follow-tags: 检查用户关注的标签
        if (class_exists('\FoF\FollowTags\Models\UserTagState') && $userId) {
            try {
                $followedTags = \FoF\FollowTags\Models\UserTagState::where('user_id', $userId)
                    ->where('is_followed', true)
                    ->with('tag')
                    ->get()
                    ->pluck('tag.name')
                    ->filter()
                    ->values()
                    ->all();
                if (!empty($followedTags)) {
                    $context['followed_tags'] = implode(', ', $followedTags);
                }
            } catch (\Exception $e) {
            }
        }

        // linkrobins/auto-verify: 自动验证用户（机器人绕过）
        if (class_exists(\LinkRobins\AutoVerify\Listeners\AutoVerifyUser) && $author) {
            try {
                if (!$author->is_verified && $author->email && $author->joined_at) {
                    $daysSinceJoin = $author->joined_at->diffInDays(now());
                    if ($daysSinceJoin < 7) {
                        $context['new_user'] = true;
                    }
                }
            } catch (\Exception $e) {
            }
        }

        // tryhackx/flarum-advanced-pages: 注入高级页面内容
        if (class_exists(\TryHackX\AdvancedPages\Page)) {
            try {
                $advancedPage = \TryHackX\AdvancedPages\Page::where('discussion_id', $discussion->id)->first();
                if ($advancedPage && $advancedPage->content) {
                    $pageContent = mb_substr(strip_tags((string) $advancedPage->content), 0, 500);
                    $context['advanced_page_context'] = "高级页面「{$advancedPage->title}」：\n{$pageContent}";
                }
            } catch (\Exception $e) {
            }
        }

        // shebaoting/flarum-repost: 检测转发内容
        if (class_exists(\Shebaoting\Repost\Post\RepostedPost)) {
            try {
                $repostedPost = \Shebaoting\Repost\Post\RepostedPost::where('post_id', $post->id)->first();
                if ($repostedPost && $repostedPost->original_post_id) {
                    $originalPost = \Flarum\Post\Post::find($repostedPost->original_post_id);
                    if ($originalPost) {
                        $originalContent = mb_substr(strip_tags((string) $originalPost->content), 0, 300);
                        $context['repost_context'] = "这是一条转发内容，原文：{$originalContent}";
                    }
                }
            } catch (\Exception $e) {
            }
        }

        // ===== 媒体解析：链接摘要与文件信息注入 =====
        $context['media_context'] = $this->buildMediaContext($contentHtml, $settings);

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

        $tools = $this->buildBotTools($botUser->id, $userId, $settings, 'discussion');

        // ===== 关系网与表达风格库注入 =====
        // 关系网：长期稳定认知（身份/别名/边界）；表达库：仅已启用且作用域匹配的"怎么说"规则
        try {
            if ((bool) $settings->get('flarum-zai-bot.relation_network_enabled', true) && $userId) {
                $context['relation_summary'] = resolve(\Zephyrisle\FlarumZaiBot\Service\RelationService::class)->buildSummary($userId);
            }

            if ((bool) $settings->get('flarum-zai-bot.expression_learning_enabled', true)) {
                $expressionService = resolve(\Zephyrisle\FlarumZaiBot\Service\ExpressionService::class);
                $activeRules = $expressionService->activeRules('discussion', $userId, $discussion->id);
                if ($activeRules !== []) {
                    $context['expression_rules'] = $expressionService->buildInjectionText($activeRules);
                }
            }
        } catch (\Exception $e) {
            error_log('[flarum-zai-bot] GenerateReplyForPost: relation/expression context failed: ' . $e->getMessage());
        }

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

        // flarum/approval: 机器人帖子自动通过审批，避免等待人工审核
        if (class_exists(\Flarum\Approval\Listener\UnapproveNewContent::class)) {
            if (!$botPost->is_approved) {
                $botPost->is_approved = true;
                $botPost->save();
            }
        }

        // flarum/akismet: 机器人帖子标记为非垃圾信息
        if (class_exists(\Flarum\Akismet\Listener\ValidatePost::class)) {
            if (!empty($botPost->is_spam)) {
                $botPost->is_spam = false;
                $botPost->save();
            }
        }

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
                $this->repliesToOtherPost($candidate->formatContent()),
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
     * 用户是否处于封禁期（flarum/suspend）。
     * suspended_until 依据扩展版本可能为字符串或 Carbon 实例，两者都兼容。
     */
    protected function authorIsSuspended(?User $author): bool
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
     * 文件大小的人类可读格式（fof/upload File 模型没有 formatSize 方法）。
     */
    protected function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, $value >= 10 ? 0 : 1) . ' ' . $units[$i];
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
