<?php

namespace Zephyrisle\FlarumZaiBot\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;

class UserPortrait extends AbstractModel
{
    protected $table = 'bot_user_portraits';

    public $timestamps = true;

    protected $casts = [
        'traits' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function getOrCreate(int $userId): self
    {
        $portrait = static::where('user_id', $userId)->first();

        if (!$portrait) {
            try {
                $portrait = new static();
                $portrait->user_id = $userId;
                $portrait->summary = '';
                $portrait->traits = [];
                $portrait->save();
            } catch (\Illuminate\Database\QueryException $e) {
                // 队列并发时另一个任务可能已抢先创建成功（user_id 唯一约束），改为复用现有记录
                $portrait = static::where('user_id', $userId)->first();
            }
        }

        // 极端兜底：插入失败且重查仍为空时，返回一个带 user_id 的未保存实例
        // （避免通过构造参数赋值触发批量赋值保护 MassAssignmentException）
        if (!$portrait) {
            $portrait = new static();
            $portrait->user_id = $userId;
        }

        return $portrait;
    }

    public function addObservation(string $observation): void
    {
        $traits = $this->traits ?? [];
        $traits[] = [
            'observation' => $observation,
            'timestamp' => \Carbon\Carbon::now()->toDateTimeString(),
        ];

        if (count($traits) > 100) {
            array_shift($traits);
        }

        $this->traits = $traits;
        $this->summary = $this->generateSummary($traits);
        $this->save();
    }

    protected function generateSummary(array $traits): string
    {
        if (empty($traits)) {
            return '';
        }

        $count = count($traits);
        $last = end($traits);
        return "已观察{$count}次，最后观察：{$last['observation']}（{$last['timestamp']}）";
    }
}
