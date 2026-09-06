<?php

namespace Zephyrisle\FlarumZaiBot\Service;

use Illuminate\Support\Carbon;

/**
 * cloudnest「续火花」适配服务。
 *
 * 直接在服务端读写 cloudnest 的 Streak 模型（表 streaks）：读取会话火花状态供
 * 聊天上下文使用，机器人回复后替双方续上火花。计算逻辑与 cloudnest 的
 * StreakTouchController 完全一致（多闪式规则），未安装该扩展时所有方法静默返回
 * null，不影响其余功能。
 */
class StreakService
{
    /**
     * 按会话读取火花快照；找不到时按参与者对兜底回查。
     */
    public function readForDialog(int $botUserId, int $userId, int|string $dialogId): ?array
    {
        if (!$this->available()) {
            return null;
        }

        try {
            $streak = \CloudNest\Emoji\Streak::query()
                ->where('dialog_id', (string) $dialogId)
                ->first();

            return $streak ? $this->snapshot($streak) : $this->readForPair($botUserId, $userId);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 按参与者对读取火花快照（双方 id 顺序无关）。
     */
    public function readForPair(int $aId, int $bId): ?array
    {
        if (!$this->available()) {
            return null;
        }

        try {
            [$userA, $userB] = $aId < $bId ? [$aId, $bId] : [$bId, $aId];
            $streak = \CloudNest\Emoji\Streak::query()
                ->where('user_a_id', $userA)
                ->where('user_b_id', $userB)
                ->first();

            return $streak ? $this->snapshot($streak) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 续火花：登记 actorUserId 今天活跃，并刷新双方连续互聊天数。
     * 返回更新后的火花快照；参数非法或扩展未安装时返回 null。
     */
    public function touch(int $actorUserId, int $otherUserId, int|string $dialogId): ?array
    {
        if (!$this->available() || $otherUserId <= 0 || $otherUserId === $actorUserId) {
            return null;
        }

        try {
            // 规范参与者顺序：id 小的为 A（与 StreakTouchController 一致）
            [$userA, $userB] = $actorUserId < $otherUserId
                ? [$actorUserId, $otherUserId]
                : [$otherUserId, $actorUserId];

            $streak = \CloudNest\Emoji\Streak::firstOrNew(['dialog_id' => (string) $dialogId]);
            $streak->user_a_id = $userA;
            $streak->user_b_id = $userB;

            $today = Carbon::today()->format('Y-m-d');
            $yesterday = Carbon::yesterday()->format('Y-m-d');

            // 记录今天谁活跃过
            if ($actorUserId === $userA) {
                $streak->last_a_date = $today;
            } else {
                $streak->last_b_date = $today;
            }

            // 双方今天都活跃 → 互聊日
            if ($streak->last_a_date === $today && $streak->last_b_date === $today) {
                $lastMutual = $streak->last_mutual_date;
                if ($lastMutual === $today) {
                    // 今天已计过，保持不变
                } elseif ($lastMutual === $yesterday) {
                    $streak->current_streak += 1;
                } else {
                    // 中断后重新开始
                    $streak->current_streak = 1;
                }
                $streak->last_mutual_date = $today;
                if ($streak->current_streak > $streak->best_streak) {
                    $streak->best_streak = $streak->current_streak;
                }
            }

            $streak->save();

            return $this->snapshot($streak);
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function available(): bool
    {
        return class_exists(\CloudNest\Emoji\Streak::class);
    }

    /**
     * 把 Streak 模型转成轻量数组快照，供上下文字段与工具输出使用。
     */
    protected function snapshot(\CloudNest\Emoji\Streak $streak): array
    {
        return [
            'dialog_id' => (string) $streak->dialog_id,
            'user_a_id' => (int) $streak->user_a_id,
            'user_b_id' => (int) $streak->user_b_id,
            'current_streak' => (int) $streak->current_streak,
            'best_streak' => (int) $streak->best_streak,
            'last_a_date' => $streak->last_a_date ? (string) $streak->last_a_date : null,
            'last_b_date' => $streak->last_b_date ? (string) $streak->last_b_date : null,
            'last_mutual_date' => $streak->last_mutual_date ? (string) $streak->last_mutual_date : null,
        ];
    }
}