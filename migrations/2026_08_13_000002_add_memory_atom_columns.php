<?php

use Flarum\Database\Migration;

/**
 * 记忆原子化：为 bot_memories 增加独立记忆原子所需的列。
 *
 * 每个记忆原子分别拥有：
 *   - importance        重要度（可被召回强化提升，可因时间衰减回落）
 *   - ttl_days          存活天数（TTL），到期后 expires_at 生效、不再被召回
 *   - expires_at        过期时间（由 ttl_days 换算，null = 永不过期）
 *   - reinforce_count   强化次数（每次被召回 +1）
 *   - last_accessed_at  最近召回时间（时间衰减的依据）
 *   - archived_at       归档时间（非 null = 已归档，可恢复，不参与召回）
 *   - source_text       来源消息原文（重要记忆可核验/重新总结）
 *   - source_meta       来源元信息（JSON：讨论/帖子/消息 ID 等）
 */
return Migration::addColumns('bot_memories', [
    'importance' => ['integer', 'default' => 0],
    'ttl_days' => ['integer', 'nullable' => true],
    'expires_at' => ['dateTime', 'nullable' => true],
    'reinforce_count' => ['integer', 'default' => 0],
    'last_accessed_at' => ['dateTime', 'nullable' => true],
    'archived_at' => ['dateTime', 'nullable' => true],
    'source_text' => ['text', 'nullable' => true],
    'source_meta' => ['text', 'nullable' => true],
]);
