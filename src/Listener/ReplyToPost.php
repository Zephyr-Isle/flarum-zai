<?php

namespace Zephyrisle\FlarumZaiBot\Listener;

use Carbon\Carbon;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Posted;
use Flarum\User\User;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Str;
use Zephyrisle\FlarumZaiBot\Service\AIService;

class ReplyToPost
{
    private static bool $processing = false;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected AIService $ai
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

        if (!$shouldReply) {
            return;
        }

        $context = "Discussion title: " . ($discussion->title ?? 'Untitled');
        $reply = $this->ai->generateReply($content, $context);

        if (!$reply) {
            return;
        }

        try {
            $botPost = new CommentPost();
            $botPost->discussion_id = $post->discussion_id;
            $botPost->user_id = $botUser->id;
            $botPost->setParsedContentAttribute($reply);
            $botPost->save();
        } catch (\Exception $e) {
        }
    }
}
