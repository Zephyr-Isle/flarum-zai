<?php

namespace Zephyrisle\FlarumZaiBot\Listener;

use Flarum\Discussion\Event\Deleted as DiscussionDeleted;
use Flarum\Discussion\Event\Hidden as DiscussionHidden;
use Flarum\Discussion\Event\Renamed;
use Flarum\Discussion\Event\Restored as DiscussionRestored;
use Flarum\Discussion\Event\Started;
use Flarum\Post\Event\Deleted as PostDeleted;
use Flarum\Post\Event\Hidden as PostHidden;
use Flarum\Post\Event\Restored as PostRestored;
use Flarum\Post\Event\Revised;
use Flarum\Settings\SettingsRepositoryInterface;
use Zephyrisle\FlarumZaiBot\Model\ContextEvent;

// fof/merge-discussions & fof/move-posts & fof/split 事件（条件导入，扩展未安装时不存在）
if (class_exists('\FoF\MergeDiscussions\Events\DiscussionWasMerged')) {
    class_alias('\FoF\MergeDiscussions\Events\DiscussionWasMerged', 'ZaiBot_DiscussionWasMerged');
}
if (class_exists('\FoF\MovePosts\Event\PostsMoved')) {
    class_alias('\FoF\MovePosts\Event\PostsMoved', 'ZaiBot_PostsMoved');
}
if (class_exists('\FoF\Split\Events\DiscussionWasSplit')) {
    class_alias('\FoF\Split\Events\DiscussionWasSplit', 'ZaiBot_DiscussionWasSplit');
}
if (class_exists('\FoF\ModeratorWarnings\Events\WarningWasCreated')) {
    class_alias('\FoF\ModeratorWarnings\Events\WarningWasCreated', 'ZaiBot_WarningWasCreated');
}
if (class_exists('\FoF\ModeratorWarnings\Models\Warning')) {
    class_alias('\FoF\ModeratorWarnings\Models\Warning', 'ZaiBot_ModeratorWarning');
}

/**
 * 事件记录：把论坛中的通知/管理类事件写入 bot_context_events 表，
 * 供 ContextInjectionService 在回复前聚合注入给模型。
 *
 * 映射说明（QQ 群聊 → 论坛）：
 *   - 撤回    → 帖子隐藏（Post Hidden）
 *   - 恢复    → 帖子恢复（Post Restored）
 *   - 删除    → 帖子删除（Post Deleted）
 *   - 编辑    → 帖子修订（Post Revised）
 *   - 改名    → 讨论改名（Discussion Renamed）
 *   - 新讨论  → 讨论创建（Discussion Started）
 *   - 讨论隐藏/恢复/删除 → 对应 Discussion 事件
 *
 * 记录由 ctx_event_record_enabled 开关控制（默认开）。
 *
 * 注意：本类以类名（字符串）形式注册为事件监听器（Flarum 会从容器解析
 * 并调用 handle()），不能注册为 [Class::class, 'method'] 数组——
 * 非静态方法数组不是合法的 PHP callable，会导致 Flarum 启动 TypeError。
 */
class RecordContextEvent
{
    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    /**
     * 统一入口：按事件类型分发到对应记录方法。
     */
    public function handle(object $event): void
    {
        match (true) {
            $event instanceof PostHidden => $this->onPostHidden($event),
            $event instanceof PostRestored => $this->onPostRestored($event),
            $event instanceof PostDeleted => $this->onPostDeleted($event),
            $event instanceof Revised => $this->onPostRevised($event),
            $event instanceof Started => $this->onDiscussionStarted($event),
            $event instanceof Renamed => $this->onDiscussionRenamed($event),
            $event instanceof DiscussionHidden => $this->onDiscussionHidden($event),
            $event instanceof DiscussionRestored => $this->onDiscussionRestored($event),
            $event instanceof DiscussionDeleted => $this->onDiscussionDeleted($event),
            // fof/merge-discussions: 讨论合并事件
            class_exists('ZaiBot_DiscussionWasMerged') && $event instanceof \ZaiBot_DiscussionWasMerged => $this->onDiscussionMerged($event),
            // fof/move-posts: 帖子移动事件
            class_exists('ZaiBot_PostsMoved') && $event instanceof \ZaiBot_PostsMoved => $this->onPostsMoved($event),
            // fof/split: 讨论拆分事件
            class_exists('ZaiBot_DiscussionWasSplit') && $event instanceof \ZaiBot_DiscussionWasSplit => $this->onDiscussionSplit($event),
            // fof/moderator-warnings: 警告事件
            class_exists('ZaiBot_WarningWasCreated') && $event instanceof \ZaiBot_WarningWasCreated => $this->onWarningIssued($event),
            default => null,
        };
    }

    public function onPostHidden(PostHidden $event): void
    {
        $this->record(
            $event->post->discussion_id,
            $event->post->id,
            $event->actor?->id,
            'post_hidden',
            "帖子 #{$event->post->id} 被隐藏（撤回）"
        );
    }

    public function onPostRestored(PostRestored $event): void
    {
        $this->record(
            $event->post->discussion_id,
            $event->post->id,
            $event->actor?->id,
            'post_restored',
            "帖子 #{$event->post->id} 被恢复"
        );
    }

    public function onPostDeleted(PostDeleted $event): void
    {
        $this->record(
            $event->post->discussion_id,
            $event->post->id,
            $event->actor?->id,
            'post_deleted',
            "帖子 #{$event->post->id} 被删除"
        );
    }

    public function onPostRevised(Revised $event): void
    {
        $this->record(
            $event->post->discussion_id,
            $event->post->id,
            $event->actor->id,
            'post_revised',
            "帖子 #{$event->post->id} 被编辑"
        );
    }

    public function onDiscussionStarted(Started $event): void
    {
        $this->record(
            $event->discussion->id,
            null,
            $event->actor?->id,
            'discussion_started',
            "新讨论「{$event->discussion->title}」创建"
        );
    }

    public function onDiscussionRenamed(Renamed $event): void
    {
        $this->record(
            $event->discussion->id,
            null,
            $event->actor?->id,
            'discussion_renamed',
            "讨论标题由「{$event->oldTitle}」改为「{$event->discussion->title}」"
        );
    }

    public function onDiscussionHidden(DiscussionHidden $event): void
    {
        $this->record(
            $event->discussion->id,
            null,
            $event->actor?->id,
            'discussion_hidden',
            "讨论「{$event->discussion->title}」被隐藏"
        );
    }

    public function onDiscussionRestored(DiscussionRestored $event): void
    {
        $this->record(
            $event->discussion->id,
            null,
            $event->actor?->id,
            'discussion_restored',
            "讨论「{$event->discussion->title}」被恢复"
        );
    }

    public function onDiscussionDeleted(DiscussionDeleted $event): void
    {
        $this->record(
            $event->discussion->id,
            null,
            $event->actor?->id,
            'discussion_deleted',
            "讨论「{$event->discussion->title}」被删除"
        );
    }

    /**
     * fof/merge-discussions: 讨论被合并
     */
    public function onDiscussionMerged($event): void
    {
        $mergedIds = $event->mergedDiscussions->pluck('id')->implode(',');
        $this->record(
            $event->discussion->id,
            null,
            $event->actor?->id ?? null,
            'discussion_merged',
            "讨论 [{$mergedIds}] 被合并到讨论「{$event->discussion->title}」(ID:{$event->discussion->id})"
        );
    }

    /**
     * fof/move-posts: 帖子被移动
     */
    public function onPostsMoved($event): void
    {
        $postIds = $event->posts->pluck('id')->implode(',');
        $this->record(
            $event->sourceDiscussion->id,
            null,
            $event->actor?->id ?? null,
            'posts_moved',
            "帖子 [{$postIds}] 从讨论「{$event->sourceDiscussion->title}」移动到讨论「{$event->targetDiscussion->title}」"
        );
    }

    /**
     * fof/split: 讨论被拆分
     * （DiscussionWasSplit 的属性是 originalDiscussion / newDiscussion，没有 $discussion）
     */
    public function onDiscussionSplit($event): void
    {
        $postIds = $event->posts->pluck('id')->implode(',');
        $this->record(
            $event->originalDiscussion->id,
            null,
            $event->actor?->id ?? null,
            'discussion_split',
            "帖子 [{$postIds}] 从讨论「{$event->originalDiscussion->title}」拆分到新讨论「{$event->newDiscussion->title}」(ID:{$event->newDiscussion->id})"
        );
    }

    /**
     * fof/moderator-warnings: 用户收到警告
     */
    public function onWarningIssued($event): void
    {
        // 事件对象属性异常时静默跳过，不影响正常的警告流程
        if (!isset($event->warning)) {
            return;
        }

        $this->record(
            null,
            null,
            $event->warning->user_id ?? null,
            'warning_issued',
            "用户收到警告（类型：" . ($event->warning->type ?? '未知') . "）"
        );
    }

    /**
     * 写入一条事件记录；开关关闭或写入失败时静默跳过。
     */
    protected function record(?int $discussionId, ?int $postId, ?int $userId, string $type, string $description): void
    {
        if (!$this->enabled()) {
            return;
        }

        try {
            $event = new ContextEvent();
            $event->discussion_id = $discussionId;
            $event->post_id = $postId;
            $event->user_id = $userId;
            $event->type = $type;
            $event->description = $description;
            $event->save();
        } catch (\Exception $e) {
            error_log('[flarum-zai-bot] RecordContextEvent failed: ' . $e->getMessage());
        }
    }

    protected function enabled(): bool
    {
        return (bool) $this->settings->get('flarum-zai-bot.ctx_event_record_enabled', true);
    }
}
