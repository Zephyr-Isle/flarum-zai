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
            $portrait = new static();
            $portrait->user_id = $userId;
            $portrait->summary = '';
            $portrait->traits = [];
            $portrait->save();
        }

        return $portrait;
    }

    public function addObservation(string $observation): void
    {
        $traits = $this->traits ?? [];
        $traits[] = [
            'observation' => $observation,
            'timestamp' => now()->toDateTimeString(),
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
