<?php

namespace Zephyrisle\FlarumZaiBot\Job;

use Carbon\Carbon;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Posted;
use Flarum\Post\Post;
use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Zephyrisle\FlarumZaiBot\Service\AIService;

class GenerateReplyForPost extends AbstractJob
{
    public int $postId;

    public function __construct(int $postId)
    {
        $this->postId = $postId;
    }

    public function handle(AIService $ai, SettingsRepositoryInterface $settings, Dispatcher $events): void
    {
        $post = Post::find($this->postId);

        if (!$post || !$post->discussion) {
            return;
        }

        $discussion = $post->discussion;

        $botUsername = $settings->get('flarum-zai-bot.username', 'AIGirl');
        $randomChance = (int) $settings->get('flarum-zai-bot.random_reply_chance', 0);

        $botUser = User::where('username', $botUsername)->first();

        if (!$botUser) {
            $botUser = new User();
            $botUser->username = $botUsername;
            $botUser->email = $botUsername . '@bot.local';
            $botUser->password = \Illuminate\Support\Str::random(40);
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
        $reply = $ai->generateReply($content, $context);

        if (!$reply) {
            return;
        }

        $botPost = new CommentPost();
        $botPost->discussion_id = $post->discussion_id;
        $botPost->user_id = $botUser->id;
        $botPost->created_at = Carbon::now();
        $botPost->setContentAttribute($reply, $botUser);
        $botPost->save();

        $events->dispatch(new Posted($botPost));
    }
}
