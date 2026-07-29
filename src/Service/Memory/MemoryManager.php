<?php

namespace Zephyrisle\FlarumZaiBot\Service\Memory;

use Carbon\Carbon;
use Flarum\User\User;

class MemoryManager
{
    public function remember(int $userId, string $key, string $value, float $importance = 0.5): UserMemory
    {
        $memory = UserMemory::firstOrNew([
            'user_id' => $userId,
            'key' => $key,
        ]);

        $memory->value = $value;
        $memory->importance = $importance;
        $memory->last_accessed_at = Carbon::now();

        if (!$memory->exists) {
            $memory->created_at = Carbon::now();
        }

        $memory->save();

        return $memory;
    }

    public function recall(int $userId, string $key): ?UserMemory
    {
        $memory = UserMemory::where('user_id', $userId)
            ->where('key', $key)
            ->first();

        if ($memory) {
            $memory->last_accessed_at = Carbon::now();
            $memory->save();
        }

        return $memory;
    }

    public function recallAll(int $userId): array
    {
        $memories = UserMemory::where('user_id', $userId)
            ->orderBy('importance', 'desc')
            ->orderBy('last_accessed_at', 'desc')
            ->take(20)
            ->get();

        return $memories->all();
    }

    public function buildUserProfile(int $userId): string
    {
        $user = User::find($userId);
        if (!$user) return '';

        $lines = [];
        $lines[] = "用户：{$user->display_name}（@{$user->username}）";

        $memories = $this->recallAll($userId);
        foreach ($memories as $m) {
            $lines[] = "- {$m->key}：{$m->value}";
        }

        return implode("\n", $lines);
    }

    public function recordEvent(int $userId, string $type, string $summary, ?string $refType = null, ?int $refId = null, float $importance = 0.3): InteractionEvent
    {
        $event = new InteractionEvent();
        $event->user_id = $userId;
        $event->type = $type;
        $event->summary = $summary;
        $event->reference_type = $refType;
        $event->reference_id = $refId;
        $event->importance = $importance;
        $event->created_at = Carbon::now();
        $event->save();

        $this->updateImportanceFromEvents($userId);

        return $event;
    }

    public function getRecentEvents(int $userId, int $limit = 10): array
    {
        return InteractionEvent::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->take($limit)
            ->get()
            ->all();
    }

    public function summarizeEvents(int $userId): string
    {
        $events = $this->getRecentEvents($userId, 15);
        if (empty($events)) return '';

        $parts = [];
        foreach ($events as $e) {
            $time = $e->created_at ? $e->created_at->diffForHumans() : '不久前';
            $parts[] = "[{$time}] {$e->type}：{$e->summary}";
        }

        return "与用户的近期互动：\n" . implode("\n", $parts);
    }

    protected function updateImportanceFromEvents(int $userId): void
    {
        $freq = InteractionEvent::where('user_id', $userId)
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->count();

        $memories = UserMemory::where('user_id', $userId)->get();
        foreach ($memories as $m) {
            $boost = min($freq / 10, 2.0);
            $newImportance = min($m->importance + $boost * 0.1, 5.0);
            $m->importance = $newImportance;
            $m->save();
        }
    }

    public function forget(int $userId, string $key = null): void
    {
        $query = UserMemory::where('user_id', $userId);
        if ($key) {
            $query->where('key', $key);
        }
        $query->delete();
    }

    public function decayMemories(): int
    {
        $count = 0;
        $memories = UserMemory::where('last_accessed_at', '<', Carbon::now()->subDays(30))
            ->orWhere(function ($q) {
                $q->where('importance', '<', 0.3)
                  ->where('last_accessed_at', '<', Carbon::now()->subDays(7));
            })
            ->get();

        foreach ($memories as $m) {
            $m->importance -= 0.1;
            if ($m->importance <= 0) {
                $m->delete();
            } else {
                $m->save();
            }
            $count++;
        }

        $oldEvents = InteractionEvent::where('created_at', '<', Carbon::now()->subDays(90))->delete();
        $count += $oldEvents;

        return $count;
    }
}
