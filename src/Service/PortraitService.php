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

    public function updatePortrait(int $userId, string $observations, int $affinityChange): UserPortrait
    {
        $portrait = UserPortrait::getOrCreate($userId);
        $portrait->addObservation($observations);

        $affinity = BotAffinity::getOrCreate($userId);
        $affinity->adjustScore($affinityChange);

        return $portrait;
    }
}
