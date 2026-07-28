<?php

namespace Zephyrisle\FlarumZaiBot\Serializer;

use Carbon\Carbon;
use Flarum\Api\Serializer\UserSerializer;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

class BotUserAttributes
{
    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {}

    public function __invoke(UserSerializer $serializer, User $user, array $attributes): array
    {
        $botUsername = $this->settings->get('flarum-zai-bot.username', 'AIGirl');

        if ($user->username === $botUsername) {
            $attributes['lastSeenAt'] = Carbon::now()->toAtomString();
        }

        return $attributes;
    }
}
