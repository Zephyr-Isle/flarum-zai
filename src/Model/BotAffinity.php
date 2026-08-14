<?php

namespace Zephyrisle\FlarumZaiBot\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;

/**
 * 细致好感度系统：
 *   - total_score  好感度主值（favor），-100 ~ 100
 *   - trust        信任度，-100 ~ 100
 *   - intimacy     亲密度，-100 ~ 100
 *   - emotions     12 维度情感状态（JSON），各 -100 ~ 100，0 为中性
 *   - attitude     印象描述（秘密评估 Attitude）
 *   - relationship 关系描述（秘密评估 Relationship）
 *   - blacklisted  黑名单熔断标记（好感度低于阈值自动加入，管理员可移除）
 */
class BotAffinity extends AbstractModel
{
    protected $table = 'bot_affinities';

    public $timestamps = true;

    /** 情感维度（12 维心理模型） */
    public const EMOTION_KEYS = [
        'joy', 'trust', 'fear', 'surprise', 'sadness', 'disgust',
        'anger', 'anticipation', 'pride', 'guilt', 'shame', 'envy',
    ];

    protected $casts = [
        'last_interaction_at' => 'datetime',
        'emotions' => 'array',
        'blacklisted' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function getOrCreate(int $userId): self
    {
        $affinity = static::where('user_id', $userId)->first();

        if (!$affinity) {
            try {
                $affinity = new static();
                $affinity->user_id = $userId;
                $affinity->total_score = 0;
                $affinity->trust = 0;
                $affinity->intimacy = 0;
                $affinity->emotions = [];
                $affinity->blacklisted = false;
                $affinity->interaction_count = 0;
                $affinity->save();
            } catch (\Illuminate\Database\QueryException $e) {
                // 队列并发时另一个任务可能已抢先创建成功（user_id 唯一约束），改为复用现有记录
                $affinity = static::where('user_id', $userId)->first();
            }
        }

        // 极端兜底：插入失败且重查仍为空时，返回一个带 user_id 的未保存实例
        // （避免通过构造参数赋值触发批量赋值保护 MassAssignmentException）
        if (!$affinity) {
            $affinity = new static();
            $affinity->user_id = $userId;
        }

        return $affinity;
    }

    /**
     * 好感度（favor）增量调整，钳制在 [-100, 100]，同时刷新互动统计。
     */
    public function adjustScore(int $delta): void
    {
        $this->total_score = $this->clamp($this->total_score + $delta);
        $this->touchInteraction();
        $this->save();
    }

    /**
     * 将好感度设置为绝对值（用于秘密评估 [Favour: x] 更新），钳制在 [-100, 100]。
     */
    public function setScore(int $score): void
    {
        $this->total_score = $this->clamp($score);
        $this->touchInteraction();
        $this->save();
    }

    /**
     * 信任度增量调整，钳制在 [-100, 100]。
     */
    public function adjustTrust(int $delta): void
    {
        $this->trust = $this->clamp(($this->trust ?? 0) + $delta);
        $this->save();
    }

    /**
     * 亲密度增量调整，钳制在 [-100, 100]。
     */
    public function adjustIntimacy(int $delta): void
    {
        $this->intimacy = $this->clamp(($this->intimacy ?? 0) + $delta);
        $this->save();
    }

    /**
     * 设置信任度绝对值（秘密评估 [Trust: x]）。
     */
    public function setTrust(int $value): void
    {
        $this->trust = $this->clamp($value);
        $this->save();
    }

    /**
     * 设置亲密度绝对值（秘密评估 [Intimacy: x]）。
     */
    public function setIntimacy(int $value): void
    {
        $this->intimacy = $this->clamp($value);
        $this->save();
    }

    /**
     * 应用情感增量：['joy' => 3, 'anger' => -10]，各维度钳制在 [-100, 100]。
     * 支持主动代谢：负值即为抵消旧情绪（如愤怒值回落）。
     *
     * @param array<string, int> $deltas
     */
    public function applyEmotionDeltas(array $deltas): void
    {
        $emotions = $this->emotions ?? [];

        foreach ($deltas as $key => $value) {
            $key = strtolower(trim((string) $key));
            if (!in_array($key, self::EMOTION_KEYS, true)) {
                continue;
            }
            $current = (int) ($emotions[$key] ?? 0);
            $emotions[$key] = $this->clamp($current + (int) $value);
        }

        $this->emotions = $emotions;
        $this->save();
    }

    /**
     * 设置单维情感绝对值（秘密评估 [Emotions: joy=30] 等）。
     */
    public function setEmotion(string $key, int $value): void
    {
        $key = strtolower(trim($key));
        if (!in_array($key, self::EMOTION_KEYS, true)) {
            return;
        }

        $emotions = $this->emotions ?? [];
        $emotions[$key] = $this->clamp($value);
        $this->emotions = $emotions;
        $this->save();
    }

    public function getEmotion(string $key, int $default = 0): int
    {
        $emotions = $this->emotions ?? [];

        return isset($emotions[$key]) ? (int) $emotions[$key] : $default;
    }

    /**
     * 设置印象描述（秘密评估 Attitude）。
     */
    public function setAttitude(?string $attitude): void
    {
        $attitude = trim((string) $attitude);
        $this->attitude = $attitude !== '' ? $attitude : null;
        $this->save();
    }

    /**
     * 设置关系描述（秘密评估 Relationship）。
     */
    public function setRelationship(?string $relationship): void
    {
        $relationship = trim((string) $relationship);
        $this->relationship = $relationship !== '' ? $relationship : null;
        $this->save();
    }

    /**
     * 加入黑名单（熔断：不再触发任何 LLM 思考与回复）。
     */
    public function blacklist(): void
    {
        $this->blacklisted = true;
        $this->save();
    }

    /**
     * 移出黑名单。
     */
    public function unblacklist(): void
    {
        $this->blacklisted = false;
        $this->save();
    }

    /**
     * 重置全部状态：好感/信任/亲密归零、情感清空、印象与关系清空、移出黑名单。
     */
    public function reset(): void
    {
        $this->total_score = 0;
        $this->trust = 0;
        $this->intimacy = 0;
        $this->emotions = [];
        $this->attitude = null;
        $this->relationship = null;
        $this->blacklisted = false;
        $this->save();
    }

    protected function clamp(int $value): int
    {
        return max(-100, min(100, $value));
    }

    protected function touchInteraction(): void
    {
        $this->interaction_count++;
        $this->last_interaction_at = \Carbon\Carbon::now();
    }
}
