<?php

use Illuminate\Database\Schema\Builder;

/**
 * 为 bot_memories 表补充 pgvector embedding 列。
 *
 * 旧迁移（2026_07_29_000005）只创建了 id/user_id/content/created_at，
 * 缺少 embedding 列，导致 MemoryService 的向量检索与存储一直静默失败。
 * pgvector 是可选的（未配置 PostgreSQL 时记忆功能自动禁用），
 * 因此这里对扩展创建与加列都做容错处理。
 *
 * 注意：列维度固定为 1536（text-embedding-3-small 默认维度），
 * 若在后台配置了其他维度的 embedding 模型（如 text-embedding-3-large 为 3072），
 * 写入会失败并记录 error_log，请保持模型维度为 1536 或自行调整迁移。
 */
return [
    'up' => function (Builder $schema) {
        $connection = $schema->getConnection();

        if (!$schema->hasTable('bot_memories')) {
            return;
        }

        // 尝试启用 pgvector 扩展（未安装时忽略，记忆功能不可用但不阻塞安装）
        try {
            $connection->statement('CREATE EXTENSION IF NOT EXISTS vector');
        } catch (\Exception $e) {
            // pgvector 未安装，记忆功能将不可用
        }

        // 添加 embedding 列（已存在则忽略）
        try {
            $columns = $connection->select("SELECT column_name FROM information_schema.columns WHERE table_name = 'bot_memories' AND column_name = 'embedding'");
            if (empty($columns)) {
                $connection->statement('ALTER TABLE bot_memories ADD COLUMN embedding vector(1536)');
            }
        } catch (\Exception $e) {
            // 加列失败（例如非 PostgreSQL 连接），忽略
        }
    },
    'down' => function (Builder $schema) {
        $connection = $schema->getConnection();

        try {
            $connection->statement('ALTER TABLE bot_memories DROP COLUMN IF EXISTS embedding');
        } catch (\Exception $e) {
            // 忽略
        }
    },
];
