<?php

namespace Zephyrisle\FlarumZaiBot\Service\Memory;

/**
 * 记忆原子评分器：混合检索的纯逻辑部分（与数据库解耦，可独立单元测试）。
 *
 * 混合检索（BM25 + 向量融合）：
 *   - 关键词路：对候选记忆做 BM25 打分（经典 BM25，k1=1.2, b=0.75，
 *     语料统计取自关键词候选集内，文档长度归一化，中英文分词见 tokenize）
 *   - 语义路：pgvector 余弦相似度（由调用方传入）
 *   - 融合：两路分数各自归一化到 [0,1] 后按权重加权
 *
 * 动态上下文（记忆原子）：
 *   - 重要度加成：importance 越高，召回排序越靠前
 *   - 时间衰减：距上次召回越久，分数越低（衰减斜率由 decayDays 控制）
 *   - 过期/归档过滤：expires_at 已到或已归档的记忆直接排除
 *
 * 强化机制（reinforce）由 MemoryService 在召回命中后写入数据库，
 * 本类只负责纯计算，不触碰存储。
 */
class MemoryRanker
{
    /** BM25 参数 */
    public const K1 = 1.2;
    public const B = 0.75;

    /** 重要度加成权重（乘子） */
    public const IMPORTANCE_BOOST = 0.05;

    /** 时间衰减的分数上限（避免超老记忆被无限压低） */
    public const MAX_DECAY = 0.4;

    /**
     * 中英文感知分词：英文按词、中文按单字二元组。
     *
     * @return string[]
     */
    public function tokenize(string $text): array
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return [];
        }

        $tokens = [];

        // 英文/数字词
        if (preg_match_all('/[a-z0-9]+/', $text, $m)) {
            foreach ($m[0] as $word) {
                if (mb_strlen($word) > 1) {
                    $tokens[] = $word;
                }
            }
        }

        // 中文：去除非中文字符后按单字二元组切分
        $cjk = preg_replace('/[^\x{4e00}-\x{9fff}]/u', '', $text);
        if ($cjk !== null && $cjk !== '') {
            $length = mb_strlen($cjk);
            for ($i = 0; $i < $length - 1; $i++) {
                $tokens[] = mb_substr($cjk, $i, 2);
            }
            if ($length === 1) {
                $tokens[] = $cjk;
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * 对一组候选记忆计算 BM25 分数。
     *
     * @param string[]   $queryTokens 查询分词
     * @param array<int, array{content: string}> $docs 候选文档（key 为记忆 id）
     * @return array<int, float> id => BM25 分数
     */
    public function bm25Scores(array $queryTokens, array $docs): array
    {
        if (empty($queryTokens) || empty($docs)) {
            return [];
        }

        $count = count($docs);

        // 每篇文档的分词与长度
        $docTokens = [];
        $docLengths = [];
        $totalLength = 0;
        foreach ($docs as $id => $doc) {
            $tokens = $this->tokenize((string) ($doc['content'] ?? ''));
            $docTokens[$id] = $tokens;
            $len = count($tokens);
            $docLengths[$id] = max(1, $len);
            $totalLength += $len;
        }
        $avgDl = $totalLength / max(1, $count);

        // 词频 df：包含该词的文档数（平滑 IDF）
        $df = [];
        foreach ($queryTokens as $term) {
            $df[$term] = 0;
            foreach ($docTokens as $tokens) {
                if (in_array($term, $tokens, true)) {
                    $df[$term]++;
                }
            }
        }

        $scores = [];
        foreach ($docs as $id => $doc) {
            $score = 0.0;
            $tokens = $docTokens[$id];
            $dl = $docLengths[$id];
            $termFreq = array_count_values($tokens);

            foreach ($queryTokens as $term) {
                $tf = $termFreq[$term] ?? 0;
                if ($tf === 0) {
                    continue;
                }

                // BM25 IDF（平滑，避免除零）
                $idf = log(1 + ($count - $df[$term] + 0.5) / ($df[$term] + 0.5));
                $denom = $tf + self::K1 * (1 - self::B + self::B * $dl / $avgDl);
                $score += $idf * ($tf * (self::K1 + 1)) / $denom;
            }

            $scores[$id] = $score;
        }

        return $scores;
    }

    /**
     * 融合排序：两路分数归一化后按权重加权。
     *
     * @param array<int, float> $vectorScores  id => 向量相似度（0..1）
     * @param array<int, float> $keywordScores id => BM25 分数（可为空数组）
     * @param float             $vectorWeight  向量路权重（0..1），关键词权重为 1 - vectorWeight
     * @return array<int, float> id => 融合分数
     */
    public function fuse(array $vectorScores, array $keywordScores, float $vectorWeight = 0.6): array
    {
        if ($vectorScores === []) {
            return [];
        }

        $vectorWeight = max(0.0, min(1.0, $vectorWeight));
        $keywordWeight = 1 - $vectorWeight;

        // 关键词分数归一化：除以最大分数（0 或全 0 时统一按 0 处理）
        $kwMax = $keywordScores === [] ? 0.0 : max($keywordScores);

        $fused = [];
        foreach ($vectorScores as $id => $vecScore) {
            $vecNorm = max(0.0, min(1.0, (float) $vecScore));
            $kwNorm = $kwMax > 0 ? ((float) ($keywordScores[$id] ?? 0)) / $kwMax : 0.0;

            $fused[$id] = $vectorWeight * $vecNorm + $keywordWeight * $kwNorm;
        }

        return $fused;
    }

    /**
     * 动态上下文：在融合分数上叠加重要度加成与时间衰减。
     *
     * @param int      $importance            重要度
     * @param int|null $daysSinceLastAccess   距上次召回的天数（null = 从未召回）
     * @param int      $decayDays             衰减周期（天）：每经过该天数衰减一档
     * @return float
     */
    public function applyDynamics(float $score, int $importance, ?int $daysSinceLastAccess, int $decayDays = 30): float
    {
        $final = $score + $importance * self::IMPORTANCE_BOOST;

        if ($daysSinceLastAccess !== null && $decayDays > 0 && $daysSinceLastAccess > 0) {
            $decay = min(self::MAX_DECAY, (float) $daysSinceLastAccess / $decayDays * 0.2);
            $final -= $decay;
        }

        return max(0.0, $final);
    }

    /**
     * 记忆是否已过期。
     *
     * @param string|int|null $expiresAt datetime 字符串或时间戳
     */
    public function isExpired($expiresAt, ?int $now = null): bool
    {
        if ($expiresAt === null || $expiresAt === '') {
            return false;
        }

        $now ??= time();
        if (is_int($expiresAt) || ctype_digit((string) $expiresAt)) {
            return (int) $expiresAt <= $now;
        }

        $ts = strtotime((string) $expiresAt);

        return $ts !== false && $ts <= $now;
    }
}
