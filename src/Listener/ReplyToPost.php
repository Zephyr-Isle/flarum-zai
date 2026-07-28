<?php

namespace Zephyrisle\FlarumZaiBot\Listener;

use Carbon\Carbon;
use Flarum\Post\Command\PostReply;
use Flarum\Post\Event\Posted;
use Flarum\User\User;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Str;
use Zephyrisle\FlarumZaiBot\Service\AIService;

class ReplyToPost
{
    private static bool $processing = false;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected AIService $ai,
        protected Dispatcher $bus
    ) {}

    public function handle(Posted $event): void
    {
        if (self::$processing) {
            return;
        }

        self::$processing = true;

        try {
            $this->process($event);
        } finally {
            self::$processing = false;
        }
    }

    protected function process(Posted $event): void
    {
        $post = $event->post;
        $discussion = $post->discussion;

        $botUsername = $this->settings->get('flarum-zai-bot.username', 'AIGirl');
        $randomChance = (int) $this->settings->get('flarum-zai-bot.random_reply_chance', 0);
        $messageExt = (bool) $this->settings->get('flarum-zai-bot.message_extension', false);

        $botUser = User::where('username', $botUsername)->first();

        if (!$botUser) {
            $botUser = new User();
            $botUser->username = $botUsername;
            $botUser->email = $botUsername . '@bot.local';
            $botUser->password = Str::random(40);
            $botUser->is_email_confirmed = true;
            $botUser->save();

            $botUser->groups()->sync([1]);
        }

        $botUser->last_seen_at = Carbon::now();
        $botUser->save();

        if ($post->user_id === $botUser->id) {
            return;
        }

        $content = $post->content;
        $shouldReply = false;

        if (preg_match('/@' . preg_quote($botUsername, '/') . '\b/i', $content)) {
            $shouldReply = true;
        }

        if (!$shouldReply && $randomChance > 0 && random_int(1, 100) <= $randomChance) {
            $shouldReply = true;
        }

        if (!$shouldReply && $messageExt) {
            $botPosted = $discussion->posts()->where('user_id', $botUser->id)->exists();
            if ($botPosted) {
                $shouldReply = true;
            }
        }

        if (!$shouldReply) {
            return;
        }

        $context = "Discussion title: " . ($discussion->title ?? 'Untitled');
        $reply = $this->ai->generateReply($content, $context);

        if (!$reply) {
            return;
        }

        try {
            $this->bus->dispatch(
                new PostReply($post->discussion_id, $botUser, ['content' => $reply])
            );
        } catch (\Exception $e) {
            // Log or silently fail
        }
    }
}
