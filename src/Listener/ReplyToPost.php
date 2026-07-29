<?php

namespace Zephyrisle\FlarumZaiBot\Listener;

use Flarum\Post\Event\Posted;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Zephyrisle\FlarumZaiBot\Job\GenerateReplyForPost;
use Zephyrisle\FlarumZaiBot\Service\BotAccountManager;

class ReplyToPost
{
    public function __construct(
        protected Dispatcher $bus,
        protected SettingsRepositoryInterface $settings,
        protected BotAccountManager $accountManager
    ) {}

    public function handle(Posted $event): void
    {
        $post = $event->post;

        $account = $this->accountManager->getActiveAccount();
        if (!$account) return;

        $botUser = $this->accountManager->getOrCreateBotUser($account['username']);
        if ($post->user_id === $botUser->id) return;

        $content = $post->content;
        $shouldDispatch = false;

        if (preg_match('/@' . preg_quote($account['username'], '/') . '\b/i', $content)) {
            $shouldDispatch = true;
        }

        $randomChance = (int) $this->settings->get('flarum-zai-bot.random_reply_chance', 0);
        if (!$shouldDispatch && $randomChance > 0 && random_int(1, 100) <= $randomChance) {
            $shouldDispatch = true;
        }

        $autoEngage = (bool) $this->settings->get('flarum-zai-bot.auto_engage', false);
        if (!$shouldDispatch && $autoEngage) {
            $chance = (int) $this->settings->get('flarum-zai-bot.auto_engage_chance', 20);
            if (random_int(1, 100) <= $chance) {
                $shouldDispatch = true;
            }
        }

        if (!$shouldDispatch) return;

        $this->bus->dispatch(new GenerateReplyForPost($post->id));
    }
}
