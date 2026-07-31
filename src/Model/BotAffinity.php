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
            $affinity = new static();
            $affinity->user_id = $userId;
            $affinity->total_score = 0;
            $affinity->interaction_count = 0;
            $affinity->save();
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
}
