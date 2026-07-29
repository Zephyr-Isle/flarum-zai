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
use Zephyrisle\FlarumZaiBot\Service\BotAccountManager;
use Zephyrisle\FlarumZaiBot\Service\Memory\MemoryManager;
use Zephyrisle\FlarumZaiBot\Service\Tool\LikeTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\SearchTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\StickerTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\UserInfoTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\ViewFileTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\WebSearchTool;

class GenerateReplyForPost extends AbstractJob
{
    public int $postId;

    public function __construct(int $postId)
    {
        $this->postId = $postId;
    }

    public function handle(
        AIService $ai,
        SettingsRepositoryInterface $settings,
        Dispatcher $events,
        BotAccountManager $accountManager,
        MemoryManager $memory
    ): void {
        $post = Post::find($this->postId);
        if (!$post || !$post->discussion) return;

        $discussion = $post->discussion;
        $account = $accountManager->getActiveAccount();
        if (!$account) return;

        $botUser = $accountManager->getOrCreateBotUser($account['username']);
        if ($post->user_id === $botUser->id) return;

        $content = $post->content;
        $shouldReply = false;
        $randomChance = (int) $settings->get('flarum-zai-bot.random_reply_chance', 0);

        if (preg_match('/@' . preg_quote($account['username'], '/') . '\b/i', $content)) {
            $shouldReply = true;
        }
        if (!$shouldReply && $randomChance > 0 && random_int(1, 100) <= $randomChance) {
            $shouldReply = true;
        }
        if (!$shouldReply) return;

        $author = $post->user;
        $isVerified = false;
        if ($author && class_exists(\Ramon\Verified\TierResolver::class)) {
            $isVerified = resolve(\Ramon\Verified\TierResolver::class)->isVerified($author);
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

        $context = [
            'current_post_id' => $post->id,
            'discussion_title' => $discussion->title ?? '',
            'username' => $author ? $author->username : '',
            'display_name' => $author ? $author->display_name : '',
            'is_verified' => $isVerified,
            'conversation_history' => $history,
        ];

        if ($author) {
            $context['joined_at'] = $author->joined_at?->format('Y-m-d H:i:s');
            $context['post_count'] = $author->posts()->count();
            $context['group_names'] = $author->groups->pluck('name_singular')->implode(', ') ?: null;
            if (class_exists(\FoF\UserBio\Event\BioChanged::class) && $author->bio) {
                $context['bio'] = strip_tags($author->bio);
            }
            if (class_exists(\Datlechin\Birthdays\AddBirthdayValidation::class) && $author->birthday) {
                $context['birthday'] = $author->birthday;
            }
            if (class_exists(\Ramon\Verified\Models\UserVerification::class)) {
                $v = \Ramon\Verified\Models\UserVerification::where('user_id', $author->id)->first();
                if ($v) {
                    $context['verified_tier'] = $v->verified_tier;
                    $context['verified_at'] = $v->verified_at?->format('Y-m-d H:i:s');
                }
            }
        }

        $memory->rememberUser($botUser->id, $author, [
            'last_topic' => $discussion->title,
            'last_reply' => mb_substr(strip_tags($content), 0, 200),
        ]);
        $memory->rememberInteraction($botUser->id, "回复了 {$author->display_name} 在「{$discussion->title}」中的帖子");

        $tools = [
            new UserInfoTool(),
            new SearchTool(),
            new ViewFileTool(),
            new StickerTool(),
            new LikeTool($botUser->id),
            new WebSearchTool(),
        ];

        $reply = $ai->generateReply($content, $context, $tools, $account);

        if (!$reply) return;

        $botPost = new CommentPost();
        $botPost->discussion_id = $post->discussion_id;
        $botPost->user_id = $botUser->id;
        $botPost->created_at = Carbon::now();
        $botPost->setContentAttribute($reply, $botUser);
        $botPost->save();

        $events->dispatch(new Posted($botPost));
    }
}
