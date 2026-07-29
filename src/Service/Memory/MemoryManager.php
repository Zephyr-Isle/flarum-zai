<?php

namespace Zephyrisle\FlarumZaiBot\Service\Memory;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;

class BotMemory extends AbstractModel
{
    protected $table = 'zai_bot_memory';

    public $timestamps = false;

    protected $casts = [
        'data' => 'array',
        'created_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}

class MemoryManager
{
    const int DEFAULT_TTL_DAYS = 90;

    public function remember(int $botUserId, string $type, string $key, mixed $value, int $ttlDays = self::DEFAULT_TTL_DAYS): void
    {
        BotMemory::updateOrCreate(
            ['bot_user_id' => $botUserId, 'type' => $type, 'key' => $key],
            [
                'data' => ['value' => $value],
                'created_at' => Carbon::now(),
                'expires_at' => Carbon::now()->addDays($ttlDays),
            ]
        );
    }

    public function recall(int $botUserId, string $type, ?string $key = null): array
    {
        $query = BotMemory::where('bot_user_id', $botUserId)
            ->where('type', $type)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now());
            });

        if ($key !== null) {
            $query->where('key', $key);
        }

        return $query->get()->keyBy('key')->map(fn ($m) => $m->data['value'] ?? null)->toArray();
    }

    public function rememberUser(int $botUserId, User $user, array $traits = []): void
    {
        $profile = $this->recall($botUserId, 'user_profile', (string) $user->id);
        $profile = array_merge($profile, $traits, [
            'username' => $user->username,
            'display_name' => $user->display_name,
            'last_seen' => Carbon::now()->toDateTimeString(),
            'interaction_count' => ($profile['interaction_count'] ?? 0) + 1,
        ]);
        $this->remember($botUserId, 'user_profile', (string) $user->id, $profile, 365);
    }

    public function getUserProfile(int $botUserId, int $userId): ?array
    {
        $data = $this->recall($botUserId, 'user_profile', (string) $userId);
        return $data ?: null;
    }

    public function rememberInteraction(int $botUserId, string $summary): void
    {
        $events = $this->recall($botUserId, 'key_events', 'recent') ?: [];
        $events[] = ['time' => Carbon::now()->toDateTimeString(), 'summary' => $summary];
        $events = array_slice($events, -50);
        $this->remember($botUserId, 'key_events', 'recent', $events, 30);
    }

    public function getRecentInteractions(int $botUserId): array
    {
        return $this->recall($botUserId, 'key_events', 'recent') ?: [];
    }

    public function forget(int $botUserId, string $type, ?string $key = null): void
    {
        $query = BotMemory::where('bot_user_id', $botUserId)->where('type', $type);
        if ($key !== null) $query->where('key', $key);
        $query->delete();
    }

    public function purgeExpired(): int
    {
        return BotMemory::where('expires_at', '<', Carbon::now())->delete();
    }

    public function buildMemoryContext(int $botUserId): string
    {
        $parts = [];

        $recent = $this->getRecentInteractions($botUserId);
        if (!empty($recent)) {
            $lines = array_map(fn ($e) => "- {$e['time']}：{$e['summary']}", array_slice($recent, -10));
            $parts[] = "近期互动：\n" . implode("\n", $lines);
        }

        $allProfiles = BotMemory::where('bot_user_id', $botUserId)
            ->where('type', 'user_profile')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now());
            })
            ->get();

        if ($allProfiles->isNotEmpty()) {
            $lines = [];
            foreach ($allProfiles as $profile) {
                $data = $profile->data['value'] ?? [];
                $lines[] = "- 用户 {$data['display_name']}（{$data['username']}）：已互动 {$data['interaction_count']} 次";
            }
            $parts[] = "已知用户：\n" . implode("\n", $lines);
        }

        return implode("\n\n", $parts);
    }
}
