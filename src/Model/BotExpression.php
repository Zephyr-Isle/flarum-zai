<?php

namespace Zephyrisle\FlarumZaiBot\Model;

use Flarum\Database\AbstractModel;

/**
 * 表达学习规则：只保存"怎么说"（短表达/句法/情境风格），
 * 与关系事实（关系网/记忆）是两类数据。
 *
 *   - status: pending（待审核，不进入回复）/ active（已启用）/ disabled（已禁用）
 *   - evidence: 证据（用户原话简短引用，只读）
 *   - use_count: 使用统计（只读，由 AI 的 [ExprUsed] 上报递增）
 */
class BotExpression extends AbstractModel
{
    protected $table = 'bot_expressions';

    public $timestamps = true;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    protected $casts = [
        'recall_tags' => 'array',
        'scope' => 'array',
        'evidence' => 'array',
        'use_count' => 'integer',
    ];

    public function recordUse(): void
    {
        $this->use_count = ($this->use_count ?? 0) + 1;
        $this->save();
    }

    /**
     * 规则是否适用于当前会话（作用域匹配）：
     * scope 为空时全局适用；否则按 channel / user / discussion 交集过滤。
     */
    public function matchesScope(string $channel, ?int $userId, ?int $discussionId): bool
    {
        $scope = $this->scope ?? [];

        $channels = $scope['channels'] ?? null;
        if (is_array($channels) && $channels !== [] && !in_array($channel, $channels, true)) {
            return false;
        }

        $users = $scope['users'] ?? null;
        if (is_array($users) && $users !== [] && ($userId === null || !in_array($userId, array_map('intval', $users), true))) {
            return false;
        }

        $discussions = $scope['discussions'] ?? null;
        if (is_array($discussions) && $discussions !== [] && ($discussionId === null || !in_array($discussionId, array_map('intval', $discussions), true))) {
            return false;
        }

        return true;
    }
}
