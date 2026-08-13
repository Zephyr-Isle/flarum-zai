<?php

namespace Zephyrisle\FlarumZaiBot\Service;

use Flarum\Settings\SettingsRepositoryInterface;
use Zephyrisle\FlarumZaiBot\Service\Memory\MemoryRanker;

/**
 * 长期记忆服务：记忆原子 + 混合检索。
 *
 * 记忆原子（每条记忆一行）：
 *   - importance        重要度（召回命中时强化提升，封顶见 IMPORTANCE_CAP）
 *   - ttl_days/expires_at 存活期（TTL），到期后不再召回（过期视为低价值，可归档）
 *   - reinforce_count   强化次数（召回命中 +1）
 *   - last_accessed_at  最近召回时间（时间衰减依据）
 *   - archived_at       归档时间（非 null = 已归档，可恢复，不参与召回）
 *   - source_text       来源消息原文（重要记忆可核验、重新总结）
 *   - source_meta       来源元信息（JSON：讨论/帖子/消息 ID 等）
 *
 * 混合检索（BM25 + 向量融合）：
 *   - 语义路：pgvector 余弦相似度取 top-K 候选
 *   - 关键词路：按查询分词做 ILIKE 匹配取候选，再以 MemoryRanker 计算 BM25
 *   - 融合：两路分数归一化后按 memory_hybrid_vector_weight 加权（默认向量 0.6 / 关键词 0.4）
 *   - 动态上下文：融合分数上叠加重要度加成与时间衰减
 *   - 强化：召回命中后写入 reinforce_count +1、importance +1、last_accessed_at 刷新
 *
 * Agent 原生工具：recall_long_term_memory / memorize_long_term_memory
 * （见 RecallMemoryTool / MemorizeMemoryTool）。
 */
class MemoryService
{
    /** 重要度封顶 */
    public const IMPORTANCE_CAP = 10;

    /** 向量路取候选的数量系数（最终 limit 的倍数） */
    private const CANDIDATE_MULTIPLIER = 4;

    protected ?\PDO $pdo = null;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected EmbeddingService $embeddings
    ) {}

    public function isAvailable(): bool
    {
        return $this->getPdo() !== null;
    }

    public function generateEmbedding(string $text): ?array
    {
        return $this->embeddings->generateEmbedding($text);
    }

    /**
     * 混合检索：语义 + 关键词双路召回，融合排序后返回。
     *
     * @param int    $userId   用户 ID
     * @param array  $queryEmbedding 查询的向量
     * @param int    $limit    返回条数
     * @param string $query    查询原文（用于关键词路 BM25，可为空字符串）
     * @return array<int, array> 记忆列表，含 content/created_at/importance/source_text/source_meta 等
     */
    public function searchMemories(int $userId, array $queryEmbedding, int $limit = 5, string $query = ''): array
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            return [];
        }

        $limit = max(1, min(20, $limit));
        $candidateLimit = $limit * self::CANDIDATE_MULTIPLIER;

        try {
            $vectorStr = '[' . implode(',', $queryEmbedding) . ']';

            // ===== 语义路：pgvector 余弦相似度取候选 =====
            $stmt = $pdo->prepare("\n                SELECT id, content, created_at, importance, reinforce_count,\n                       last_accessed_at, expires_at, archived_at, source_text, source_meta,\n                       1 - (embedding <=> ?::vector) AS sim\n                FROM bot_memories\n                WHERE user_id = ? AND archived_at IS NULL\n                  AND (expires_at IS NULL OR expires_at > NOW())\n                ORDER BY embedding <=> ?::vector\n                LIMIT ?\n            ");
            $stmt->execute([$vectorStr, $userId, $vectorStr, $candidateLimit]);
            $vectorRows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            if ($vectorRows === []) {
                return [];
            }

            // ===== 关键词路：按查询分词 ILIKE 匹配取候选 =====
            $ranker = $this->ranker();
            $tokens = $ranker->tokenize($query);
            $keywordRows = [];
            if ($tokens !== []) {
                $keywordRows = $this->keywordCandidates($pdo, $userId, $tokens, $candidateLimit);
            }

            // ===== 融合排序 =====
            $docs = [];
            foreach ($vectorRows as $row) {
                $docs[(int) $row['id']] = ['content' => $row['content']];
            }

            $vectorScores = [];
            foreach ($vectorRows as $row) {
                $vectorScores[(int) $row['id']] = (float) $row['sim'];
            }

            $keywordScores = [];
            if ($keywordRows !== []) {
                $keywordDocs = [];
                foreach ($keywordRows as $row) {
                    $keywordDocs[(int) $row['id']] = ['content' => $row['content']];
                }
                $keywordScores = $ranker->bm25Scores($tokens, $keywordDocs);
            }

            $vectorWeight = $this->vectorWeight();
            $fused = $ranker->fuse($vectorScores, $keywordScores, $vectorWeight);

            $now = time();
            $decayDays = $this->decayDays();

            // 按记忆 id 归并完整行
            $rowsById = [];
            foreach ($vectorRows as $row) {
                $rowsById[(int) $row['id']] = $row;
            }
            foreach ($keywordRows as $row) {
                $rowsById[(int) $row['id']] ??= $row;
            }

            $scored = [];
            foreach ($fused as $id => $fuseScore) {
                $row = $rowsById[$id];
                $lastAccess = $row['last_accessed_at'] ?? null;
                $daysSince = $lastAccess ? max(0, (int) floor(($now - strtotime($lastAccess)) / 86400)) : null;

                $final = $ranker->applyDynamics(
                    $fuseScore,
                    (int) ($row['importance'] ?? 0),
                    $daysSince,
                    $decayDays
                );

                $scored[] = ['id' => $id, 'score' => $final, 'row' => $row];
            }

            usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
            $top = array_slice($scored, 0, $limit);

            // ===== 强化：召回命中后提升重要度并刷新访问时间 =====
            $ids = array_map(fn ($item) => $item['id'], $top);
            $this->reinforceIds($pdo, $ids);

            $result = [];
            foreach ($top as $item) {
                $row = $item['row'];
                $result[] = [
                    'id' => (int) $row['id'],
                    'content' => (string) $row['content'],
                    'created_at' => (string) $row['created_at'],
                    'importance' => (int) ($row['importance'] ?? 0),
                    'source_text' => $row['source_text'] ?? null,
                    'source_meta' => $row['source_meta'] ?? null,
                    'score' => round($item['score'], 4),
                ];
            }

            return $result;
        } catch (\Exception $e) {
            error_log('[flarum-zai-bot] searchMemories failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 关键词路：按查询分词做 ILIKE 匹配，返回候选行。
     */
    protected function keywordCandidates(\PDO $pdo, int $userId, array $tokens, int $limit): array
    {
        $conditions = [];
        $params = [$userId];
        foreach ($tokens as $token) {
            $conditions[] = 'content LIKE ?';
            $params[] = '%' . $token . '%';
        }
        $params[] = $limit;

        $sql = "\n            SELECT id, content, created_at, importance, reinforce_count,\n                   last_accessed_at, expires_at, archived_at, source_text, source_meta,\n                   0 AS sim\n            FROM bot_memories\n            WHERE user_id = ? AND archived_at IS NULL\n              AND (expires_at IS NULL OR expires_at > NOW())\n              AND (" . implode(' OR ', $conditions) . ")\n            LIMIT ?\n        ";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 存储一条记忆原子。
     *
     * @param array $options importance / ttl_days / source_text / source_meta
     */
    public function storeMemory(int $userId, string $content, array $embedding, array $options = []): bool
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            return false;
        }

        try {
            $vectorStr = '[' . implode(',', $embedding) . ']';
            $importance = max(0, (int) ($options['importance'] ?? 0));
            $ttlDays = isset($options['ttl_days']) ? max(1, (int) $options['ttl_days']) : null;
            $sourceText = $options['source_text'] ?? null;
            $sourceMeta = $options['source_meta'] ?? null;

            if ($ttlDays !== null) {
                $stmt = $pdo->prepare("\n                    INSERT INTO bot_memories\n                        (user_id, content, embedding, importance, ttl_days, expires_at, source_text, source_meta, created_at)\n                    VALUES (?, ?, ?::vector, ?, ?, NOW() + (? || ' days')::interval, ?, ?, NOW())\n                ");
                $stmt->execute([$userId, $content, $vectorStr, $importance, $ttlDays, $ttlDays, $sourceText, $sourceMeta]);
            } else {
                $stmt = $pdo->prepare("\n                    INSERT INTO bot_memories\n                        (user_id, content, embedding, importance, ttl_days, expires_at, source_text, source_meta, created_at)\n                    VALUES (?, ?, ?::vector, ?, NULL, NULL, ?, ?, NOW())\n                ");
                $stmt->execute([$userId, $content, $vectorStr, $importance, $sourceText, $sourceMeta]);
            }

            return true;
        } catch (\Exception $e) {
            error_log('[flarum-zai-bot] storeMemory failed (check embedding dimension matches vector(1024)): ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 记忆单条：按 ID 查询（含已归档，便于核验/恢复）。
     */
    public function getMemory(int $id): ?array
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            return null;
        }

        try {
            $stmt = $pdo->prepare('SELECT * FROM bot_memories WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $row ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 归档一条记忆（不参与召回，可恢复）。
     */
    public function archiveMemory(int $id): bool
    {
        return $this->updateMemory($id, ['archived_at' => 'NOW()']);
    }

    /**
     * 恢复已归档的记忆。
     */
    public function restoreMemory(int $id): bool
    {
        return $this->updateMemory($id, ['archived_at' => 'NULL']);
    }

    /**
     * 删除一条记忆（硬删除）。
     */
    public function deleteMemory(int $id): bool
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            return false;
        }

        try {
            $stmt = $pdo->prepare('DELETE FROM bot_memories WHERE id = ?');
            $stmt->execute([$id]);

            return $stmt->rowCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Agent 原生工具：recall_long_term_memory 的底层实现。
     * 由查询文本生成向量后走混合检索。
     */
    public function recall(int $userId, string $query, int $limit = 5): array
    {
        $embedding = $this->generateEmbedding($query);
        if (!$embedding) {
            return [];
        }

        return $this->searchMemories($userId, $embedding, $limit, $query);
    }

    /**
     * Agent 原生工具：memorize_long_term_memory 的底层实现。
     * 由内容生成向量后存储为记忆原子。
     *
     * @param array $options importance / ttl_days / source_text / source_meta
     */
    public function memorize(int $userId, string $content, array $options = []): bool
    {
        $embedding = $this->generateEmbedding($content);
        if (!$embedding) {
            return false;
        }

        return $this->storeMemory($userId, $content, $embedding, $options);
    }

    /**
     * 批量强化：recall 命中后提升重要度并刷新最近访问时间。
     *
     * @param int[] $ids
     */
    protected function reinforceIds(\PDO $pdo, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("\n                UPDATE bot_memories\n                SET reinforce_count = reinforce_count + 1,\n                    importance = LEAST(importance + 1, ?),\n                    last_accessed_at = NOW()\n                WHERE id IN ($placeholders)\n            ");
            $stmt->execute(array_merge([self::IMPORTANCE_CAP], $ids));
        } catch (\Exception $e) {
            error_log('[flarum-zai-bot] reinforceIds failed: ' . $e->getMessage());
        }
    }

    protected function updateMemory(int $id, array $sets): bool
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            return false;
        }

        try {
            $assignments = [];
            $params = [];
            foreach ($sets as $column => $expr) {
                if ($expr === 'NULL') {
                    $assignments[] = "{$column} = NULL";
                } else {
                    $assignments[] = "{$column} = {$expr}";
                }
            }
            $params[] = $id;

            $stmt = $pdo->prepare('UPDATE bot_memories SET ' . implode(', ', $assignments) . ' WHERE id = ?');
            $stmt->execute($params);

            return $stmt->rowCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 向量路权重（0..1），关键词路为 1 - 该值。默认 0.6。
     */
    public function vectorWeight(): float
    {
        $weight = (int) $this->settings->get('flarum-zai-bot.memory_hybrid_vector_weight', 60);

        return max(0.0, min(1.0, $weight / 100));
    }

    /**
     * 时间衰减周期（天）。默认 30。
     */
    public function decayDays(): int
    {
        return max(1, (int) $this->settings->get('flarum-zai-bot.memory_decay_days', 30));
    }

    protected function ranker(): MemoryRanker
    {
        return new MemoryRanker();
    }

    protected function getPdo(): ?\PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $host = $this->settings->get('flarum-zai-bot.pgvector_host');
        if (!$host) {
            return null;
        }

        $port = $this->settings->get('flarum-zai-bot.pgvector_port', '5432');
        $db = $this->settings->get('flarum-zai-bot.pgvector_db');
        $user = $this->settings->get('flarum-zai-bot.pgvector_user');
        $pass = $this->settings->get('flarum-zai-bot.pgvector_password');

        if (!$db || !$user) {
            return null;
        }

        try {
            $dsn = "pgsql:host={$host};port={$port};dbname={$db}";
            $this->pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
            return $this->pdo;
        } catch (\Exception $e) {
            return null;
        }
    }
}
