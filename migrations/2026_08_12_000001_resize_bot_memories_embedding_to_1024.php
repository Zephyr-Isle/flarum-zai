<?php

use Illuminate\Database\Schema\Builder;

/**
 * 将 bot_memories.embedding 列从 vector(1536) 调整为 vector(1024)，
 * 以匹配默认的 Jina Embedding 模型（jina-embeddings-v3 默认输出 1024 维）。
 *
 * 旧维度（1536）的向量无法直接转换到新维度，先清空再改列类型。
 * 与既有迁移一致：仅对 PostgreSQL/pgvector 连接生效，其余数据库静默跳过。
 */
return [
    'up' => function (Builder $schema) {
        $connection = $schema->getConnection();

        try {
            $columns = $connection->select(
                "SELECT column_name FROM information_schema.columns WHERE table_name = 'bot_memories' AND column_name = 'embedding'"
            );

            if (empty($columns)) {
                return;
            }

            $connection->statement('UPDATE bot_memories SET embedding = NULL');
            $connection->statement('ALTER TABLE bot_memories ALTER COLUMN embedding TYPE vector(1024)');
        } catch (\Exception $e) {
            // 非 pgvector/PostgreSQL 连接时静默跳过
        }
    },
    'down' => function (Builder $schema) {
        $connection = $schema->getConnection();

        try {
            $connection->statement('UPDATE bot_memories SET embedding = NULL');
            $connection->statement('ALTER TABLE bot_memories ALTER COLUMN embedding TYPE vector(1536)');
        } catch (\Exception $e) {
            // 忽略
        }
    },
];
