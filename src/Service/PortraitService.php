<?php

namespace Zephyrisle\FlarumZaiBot\Service;

use Zephyrisle\FlarumZaiBot\Model\BotAffinity;
use Zephyrisle\FlarumZaiBot\Model\UserPortrait;

class PortraitService
{
    public function getPortraitSummary(int $userId): ?string
    {
        $portrait = UserPortrait::where('user_id', $userId)->first();
        if (!$portrait || !$portrait->summary) {
            return null;
        }
        return $portrait->summary;
    }

    /**
     * 记录观察并调整好感度/信任度/亲密度。
     *
     * @param int $affinityChange 好感度变化（-5 ~ 5）
     * @param int $trustChange    信任度变化（-5 ~ 5，可选）
     * @param int $intimacyChange 亲密度变化（-5 ~ 5，可选）
     */
    public function updatePortrait(int $userId, string $observations, int $affinityChange, int $trustChange = 0, int $intimacyChange = 0): UserPortrait
    {
        $portrait = UserPortrait::getOrCreate($userId);
        $portrait->addObservation($observations);

        $affinity = BotAffinity::getOrCreate($userId);
        $affinity->adjustScore($affinityChange);
        if ($trustChange !== 0) {
            $affinity->adjustTrust($trustChange);
        }
        if ($intimacyChange !== 0) {
            $affinity->adjustIntimacy($intimacyChange);
        }

        return $portrait;
    }
}
