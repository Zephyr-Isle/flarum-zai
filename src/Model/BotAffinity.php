<?php

namespace Zephyrisle\FlarumZaiBot\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;

class BotAffinity extends AbstractModel
{
    protected $table = 'bot_affinities';

    public $timestamps = true;

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
            $affinity->total_score = 100;
            $affinity->chat_score = 0;
            $affinity->forum_score = 0;
            $affinity->interaction_count = 0;
            $affinity->save();
        }

        return $affinity;
    }

    public function addInteraction(string $channel): void
    {
        $this->interaction_count++;

        if ($channel === 'message') {
            $this->chat_score++;
        } else {
            $this->forum_score++;
        }

        $this->total_score = 100 + $this->chat_score + $this->forum_score;

        if ($this->chat_score > 100) {
            $this->chat_score = 100;
        }
        if ($this->forum_score > 100) {
            $this->forum_score = 100;
        }

        $this->last_interaction_at = now();
        $this->save();
    }
}
