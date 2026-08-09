<?php

namespace Zephyrisle\FlarumZaiBot\Service\Tool;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;

class SearchTool implements ToolInterface
{
    public function getName(): string
    {
        return 'search_forum';
    }

    public function getDescription(): string
    {
        return '在论坛中搜索讨论和帖子。返回匹配的讨论标题和帖子摘要。可以指定搜索关键词。';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => '搜索关键词',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => '返回结果数量（默认5）',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $args): string
    {
        $query = $args['query'] ?? '';
        $limit = min((int) ($args['limit'] ?? 5), 10);

        if (empty($query)) {
            return '请提供搜索关键词。';
        }

        // 转义 LIKE 通配符，避免 % _ \ 被 AI 生成的关键词或用户输入意外扩大匹配范围
        $like = $this->escapeLike($query);

        $results = [];

        $discussions = Discussion::where('title', 'like', "%{$like}%")
            ->where('is_private', false)
            ->orderBy('last_posted_at', 'desc')
            ->take($limit)
            ->get();

        foreach ($discussions as $disc) {
            $author = $disc->user ? $disc->user->display_name : '未知';
            $results[] = "[讨论] {$disc->title}（作者：{$author}，回复数：{$disc->comment_count}）";
        }

        $posts = Post::where('content', 'like', "%{$like}%")
            ->whereHas('discussion', function ($q) {
                $q->where('is_private', false);
            })
            ->where('type', 'comment')
            ->orderBy('created_at', 'desc')
            ->take($limit)
            ->get();

        foreach ($posts as $post) {
            $author = $post->user ? $post->user->display_name : '未知';
            $excerpt = mb_substr(strip_tags($post->content), 0, 100);
            $results[] = "[帖子] {$author}：{$excerpt}...（讨论：{$post->discussion->title}）";
        }

        if (empty($results)) {
            return "未找到与「{$query}」相关的内容。";
        }

        $output = "搜索「{$query}」的结果：\n";
        foreach ($results as $i => $result) {
            $output .= ($i + 1) . ". {$result}\n";
        }

        return trim($output);
    }

    /**
     * 转义 LIKE 模式中的通配符（% _ 与转义符本身），默认 ESCAPE 字符为反斜杠。
     */
    protected function escapeLike(string $query): string
    {
        return addcslashes($query, '%_\\');
    }
}
