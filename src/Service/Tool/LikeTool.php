<?php

namespace Zephyrisle\FlarumZaiBot\Service\Tool;

use Flarum\Post\Post;

class LikeTool implements ToolInterface
{
    public function getName(): string
    {
        return 'get_post_likes';
    }

    public function getDescription(): string
    {
        return '查询帖子的点赞信息。可以按帖子ID查看点赞数量、点赞用户列表。';
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
            ],
            'required' => ['post_id'],
        ];
    }

    public function execute(array $args): string
    {
        $postId = (int) ($args['post_id'] ?? 0);

        if (!$postId) {
            return '请提供有效的帖子ID。';
        }

        $post = Post::find($postId);

        if (!$post) {
            return "未找到ID为 {$postId} 的帖子。";
        }

        $author = $post->user ? $post->user->display_name : '未知';

        $output = "帖子ID：{$post->id}\n";
        $output .= "作者：{$author}\n";

        if ($post->likes_count !== null) {
            $output .= "点赞数：{$post->likes_count}\n";
        }

        if (class_exists(\Flarum\Likes\PostLikes::class)) {
            $likes = \Flarum\Likes\PostLikes::where('post_id', $postId)
                ->with('user')
                ->get();

            if ($likes->isNotEmpty()) {
                $output .= "点赞用户：\n";
                foreach ($likes as $like) {
                    $likeAuthor = $like->user ? $like->user->display_name : '未知用户';
                    $output .= "- {$likeAuthor}\n";
                }
            } else {
                $output .= "暂无点赞。\n";
            }
        } elseif (method_exists($post, 'likes')) {
            $likes = $post->likes()->with('user')->get();
            if ($likes->isNotEmpty()) {
                $output .= "点赞用户：\n";
                foreach ($likes as $like) {
                    $likeAuthor = $like->user ? $like->user->display_name : '未知用户';
                    $output .= "- {$likeAuthor}\n";
                }
            } else {
                $output .= "暂无点赞。\n";
            }
        }

        return trim($output);
    }
}
