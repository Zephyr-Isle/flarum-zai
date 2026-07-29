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
use Zephyrisle\FlarumZaiBot\Model\BotAffinity;
use Zephyrisle\FlarumZaiBot\Service\AIService;
use Zephyrisle\FlarumZaiBot\Service\Tool\LikeTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\SearchTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\SendStickerTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\StickerTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\UserInfoTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\ViewFileTool;

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

        $botUser = $this->getBotUser($botUsername);

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

        $author = $post->user;
        $isVerified = false;
        if ($author && class_exists(\Ramon\Verified\TierResolver::class)) {
            $resolver = resolve(\Ramon\Verified\TierResolver::class);
            $isVerified = $resolver->isVerified($author);
        }

        $history = [];
        $recentPosts = Post::where('discussion_id', $discussion->id)
            ->where('id', '<', $post->id)
            ->where('type', 'comment')
            ->orderBy('id', 'desc')
            ->take(10)
            ->get()
            ->reverse();

        foreach ($recentPosts as $prevPost) {
            $prevAuthor = $prevPost->user;
            $history[] = [
                'post_id' => $prevPost->id,
                'author' => $prevAuthor ? $prevAuthor->display_name : '未知',
                'content' => strip_tags($prevPost->content),
            ];
        }

        $affinity = null;
        if ($author) {
            $affinity = BotAffinity::getOrCreate($author->id);
        }

        $context = [
            'channel' => 'forum',
            'current_post_id' => $post->id,
            'discussion_title' => $discussion->title ?? 'Untitled',
            'username' => $author ? $author->username : 'unknown',
            'display_name' => $author ? $author->display_name : '未知',
            'is_verified' => $isVerified,
            'affinity_score' => $affinity?->total_score ?? null,
            'conversation_history' => $history,
        ];

        if ($author) {
            $context['joined_at'] = $author->joined_at ? $author->joined_at->format('Y-m-d H:i:s') : null;
            $context['post_count'] = $author->posts()->count();
            $context['group_names'] = $author->groups->pluck('name_singular')->implode(', ') ?: null;

            if (class_exists(\FoF\UserBio\Event\BioChanged::class) && $author->bio) {
                $context['bio'] = strip_tags($author->bio);
            }

            if (class_exists(\Datlechin\Birthdays\AddBirthdayValidation::class) && $author->birthday) {
                $context['birthday'] = $author->birthday;
            }

            if (class_exists(\Ramon\Verified\Models\UserVerification::class)) {
                $verification = \Ramon\Verified\Models\UserVerification::where('user_id', $author->id)->first();
                if ($verification) {
                    $context['verified_tier'] = $verification->verified_tier;
                    $context['verified_at'] = $verification->verified_at ? $verification->verified_at->format('Y-m-d H:i:s') : null;
                }
            }
        }

        $tools = [new UserInfoTool(), new SearchTool(), new ViewFileTool(), new StickerTool(), new SendStickerTool(), new LikeTool($botUser->id)];

        $reply = $ai->generateReply($content, $context, $tools);

        if ($author && $affinity) {
            $affinity->addInteraction('forum');
        }

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

    protected function getBotUser(string $botUsername): User
    {
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

        return $botUser;
    }
}
