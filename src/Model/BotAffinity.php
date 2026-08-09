<?php

namespace Zephyrisle\FlarumZaiBot\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;

class BotAffinity extends AbstractModel
{
    protected $table = 'bot_affinities';

    public $timestamps = true;

    protected $casts = [
        'last_interaction_at' => 'datetime',
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

    public function adjustScore(int $delta): void
    {
        $this->total_score = max(-100, min(100, $this->total_score + $delta));
        $this->interaction_count++;
        $this->last_interaction_at = \Carbon\Carbon::now();
        $this->save();
    }

    /**
     * 将好感度设置为绝对值（用于秘密评估 [Favour: x] 更新），钳制在 [-100, 100]。
     */
    public function setScore(int $score): void
    {
        $this->total_score = max(-100, min(100, $score));
        $this->interaction_count++;
        $this->last_interaction_at = \Carbon\Carbon::now();
        $this->save();
    }
}
