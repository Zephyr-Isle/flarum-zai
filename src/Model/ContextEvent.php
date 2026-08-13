<?php

namespace Zephyrisle\FlarumZaiBot\Model;

use Flarum\Database\AbstractModel;

/**
 * 讨论上下文事件记录：论坛中的通知/管理类事件（帖子撤回、恢复、删除、编辑，
 * 讨论改名、新讨论、讨论隐藏/恢复/删除）按讨论写入该表，供上下文注入时携带。
 *
 * 映射说明（QQ 群聊 → 论坛）：
 *   - 撤回        → 帖子隐藏（Flarum\Post\Event\Hidden）
 *   - 恢复        → 帖子恢复（Restored）
 *   - 删除        → 帖子删除（Deleted）
 *   - 编辑        → 帖子修订（Revised）
 *   - 改名        → 讨论改名（Discussion Renamed）
 *   - 新讨论      → 讨论创建（Discussion Started）
 *   - 讨论隐藏/恢复/删除 → 对应 Discussion 事件
 *
 * QQ 专有事件（禁言、进退群、戳一戳、精华、入群申请等）在 Flarum 中无对应概念，
 * 不记录。
 */
class ContextEvent extends AbstractModel
{
    protected $table = 'bot_context_events';

    public $timestamps = true;
}
