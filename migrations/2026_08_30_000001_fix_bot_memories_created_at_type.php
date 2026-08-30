<?php

use Illuminate\Database\Schema\Builder;

/**
 * 修复 bot_memories.created_at 列类型：建表时误建为 text。
 *
 * MemoryService::storeMemory 会向该列写入 NOW()（timestamp），
 * PostgreSQL 不存在 timestamp → text 的赋值转换，导致所有记忆写入失败：
 *   - 旧代码（ERRMODE_SILENT）下 prepare 返回 false，随后 execute() 直接抛 Error；
 *   - 修复错误模式后则变为可捕获的 SQLSTATE[42883]/类型错误。
 * 本迁移将该列转换为 timestamp，与 expires_at / last_accessed_at 等列保持一致。
 */
return [
    'up' => function (Builder $schema) {
        $connection = $schema->getConnection();

        if (!$schema->hasTable('bot_memories')) {
            return;
        }

        try {
            $column = $connection->selectOne(
                "SELECT data_type FROM information_schema.columns
                 WHERE table_name = 'bot_memories' AND column_name = 'created_at'"
            );

            // 已是时间类型（或表不存在该列）时无需处理
            if (!$column || !in_array($column->data_type, ['text', 'character varying', 'varchar'], true)) {
                return;
            }

            // 空字符串无法转换为 timestamp，先置为当前时间避免转换中断
            $connection->statement(
                "UPDATE bot_memories SET created_at = NOW() WHERE created_at IS NULL OR created_at = ''"
            );

            if ($connection->getDriverName() === 'pgsql') {
                $connection->statement(
                    'ALTER TABLE bot_memories ALTER COLUMN created_at TYPE timestamp USING created_at::timestamp'
                );
            } else {
                $connection->statement('ALTER TABLE bot_memories MODIFY created_at TIMESTAMP NULL');
            }
        } catch (\Exception $e) {
            // 非 PostgreSQL/MySQL 连接或转换失败时记录并跳过，不阻塞迁移
            error_log('[flarum-zai-bot] fix bot_memories.created_at type failed: ' . $e->getMessage());
        }
    },

    'down' => function (Builder $schema) {
        // 有损转换不做回滚：保留 timestamp 类型（旧行为本身就是错误的 text 类型）
    },
];
