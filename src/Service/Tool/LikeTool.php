<?php

namespace Zephyrisle\FlarumZaiBot\Service\Tool;

use Flarum\Likes\Event\PostWasLiked;
use Flarum\Likes\Event\PostWasUnliked;
use Flarum\Post\Post;
use Illuminate\Contracts\Events\Dispatcher;

class LikeTool implements ToolInterface
{
    public function __construct(
        protected ?int $botUserId = null
    ) {}

    public function getName(): string
    {
        return 'get_post_likes';
    }

    public function getDescription(): string
    {
        return '查询帖子的点赞信息，或对帖子进行点赞/取消点赞操作。action参数为"query"时查询点赞详情，为"like"时点赞，为"unlike"时取消点赞。';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'description' => '帖子ID',
                ],
                'action' => [
                    'type' => 'string',
                    'description' => '操作类型：query（查询，默认）、like（点赞）、unlike（取消点赞）',
                    'enum' => ['query', 'like', 'unlike'],
                ],
            ],
            'required' => ['post_id'],
        ];
    }

    public function execute(array $args): string
    {
        if (!class_exists(\Flarum\Likes\Event\PostWasLiked::class)) {
            return '未安装 flarum/likes 扩展。';
        }

        $postId = (int) ($args['post_id'] ?? 0);
        $action = $args['action'] ?? 'query';

        if (!$postId) {
            return '请提供有效的帖子ID。';
        }

        $post = Post::find($postId);

        if (!$post) {
            return "未找到ID为 {$postId} 的帖子。";
        }

        if ($action === 'like' && $this->botUserId) {
            return $this->performLike($post);
        }

        if ($action === 'unlike' && $this->botUserId) {
            return $this->performUnlike($post);
        }

        return $this->queryLikes($post);
    }

    protected function queryLikes(Post $post): string
    {
        $author = $post->user ? $post->user->display_name : '未知';
        $likeCount = $post->likes()->count();

        $output = "帖子ID：{$post->id}\n";
        $output .= "作者：{$author}\n";
        $output .= "点赞数：{$likeCount}\n";

        $likes = $post->likes()->with('groups')->get();

        if ($likes->isNotEmpty()) {
            $output .= "点赞用户：\n";
            foreach ($likes as $likeUser) {
                $output .= "- {$likeUser->display_name}\n";
            }
        } else {
            $output .= "暂无点赞。\n";
        }

        return trim($output);
    }

    protected function performLike(Post $post): string
    {
        if (!class_exists(PostWasLiked::class)) {
            return '未安装 flarum/likes 扩展。';
        }

        $botUser = \Flarum\User\User::find($this->botUserId);
        if (!$botUser) {
            return '机器人用户不存在。';
        }

        try {
            $botUser->assertCan('like', $post);
        } catch (\Exception $e) {
            return '机器人没有点赞该帖子的权限。';
        }

        $alreadyLiked = $post->likes()->where('user_id', $this->botUserId)->exists();

        if ($alreadyLiked) {
            return "已对该帖子点赞，无需重复操作。";
        }

        // flarum/likes 只维护 post_likes 透视表，posts 表没有 likes_count 列，
        // 点赞数由 API 序列化时实时计算，这里不写回帖子模型。
        $post->likes()->attach($this->botUserId);

        $events = resolve(Dispatcher::class);
        $events->dispatch(new PostWasLiked($post, $botUser));

        return "点赞成功！帖子「{$post->id}」现在有 {$post->likes()->count()} 个赞。";
    }

    protected function performUnlike(Post $post): string
    {
        if (!class_exists(PostWasUnliked::class)) {
            return '未安装 flarum/likes 扩展。';
        }

        $botUser = \Flarum\User\User::find($this->botUserId);
        if (!$botUser) {
            return '机器人用户不存在。';
        }

        try {
            $botUser->assertCan('like', $post);
        } catch (\Exception $e) {
            return '机器人没有操作该帖子点赞的权限。';
        }

        $alreadyLiked = $post->likes()->where('user_id', $this->botUserId)->exists();

        if (!$alreadyLiked) {
            return "尚未对该帖子点赞，无法取消。";
        }

        $post->likes()->detach($this->botUserId);

        $events = resolve(Dispatcher::class);
        $events->dispatch(new PostWasUnliked($post, $botUser));

        return "已取消点赞。帖子「{$post->id}」现在有 {$post->likes()->count()} 个赞。";
    }
}
