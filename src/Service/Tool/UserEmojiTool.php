<?php

namespace Zephyrisle\FlarumZaiBot\Service\Tool;

use CloudNest\Emoji\Emoji;
use CloudNest\Emoji\UserEmoji;

/**
 * cloudnest/user-emoji 工具：查询和使用用户自定义表情包。
 */
class UserEmojiTool implements ToolInterface
{
    public function __construct(
        protected ?int $userId = null
    ) {}

    public function getName(): string
    {
        return 'user_emoji';
    }

    public function getDescription(): string
    {
        return '查询和使用用户自定义表情包。action为"list"时列出我的表情包，为"search"时按名称搜索表情包，为"popular"时获取热门表情包，为"send"时获取要发送给对方的某个表情包的图片地址（query 传表情包名称）。';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'description' => '操作类型：list（列出我的表情包）、search（搜索）、popular（热门）、send（发送）',
                    'enum' => ['list', 'search', 'popular', 'send'],
                ],
                'query' => [
                    'type' => 'string',
                    'description' => '表情包名称或搜索关键词（action为search或send时必填）',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => '返回数量（默认10，最大20）',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $args): string
    {
        if (!class_exists(Emoji::class) || !class_exists(UserEmoji::class)) {
            return '未安装 cloudnest/user-emoji 扩展。';
        }

        $action = $args['action'] ?? 'list';
        $query = $args['query'] ?? '';
        $limit = max(1, min(20, (int) ($args['limit'] ?? 10)));

        switch ($action) {
            case 'list':
                return $this->listEmojis($limit);
            case 'search':
                return $query !== '' ? $this->searchEmojis($query, $limit) : '请提供搜索关键词。';
            case 'popular':
                return $this->popularEmojis($limit);
            case 'send':
                return $query !== '' ? $this->sendEmoji($query) : '请提供要发送的表情包名称。';
            default:
                return '未知操作：' . $action;
        }
    }

    protected function listEmojis(int $limit): string
    {
        if (!$this->userId) {
            return '用户未登录，无法查看表情包。';
        }

        try {
            $emojis = UserEmoji::where('user_id', $this->userId)
                ->with('emoji')
                ->orderBy('created_at', 'desc')
                ->take($limit)
                ->get();

            if ($emojis->isEmpty()) {
                return '暂无收藏的表情包。';
            }

            $output = "我的表情包（最近 {$limit} 个）：\n";
            foreach ($emojis as $userEmoji) {
                $emoji = $userEmoji->emoji;
                $name = $userEmoji->emoji_name ?? ($emoji ? ($emoji->emoji_name ?? '未命名') : '已删除');
                $url = $userEmoji->emoji_url ?? ($emoji ? $emoji->emoji_url : '');
                $output .= "- {$name}: {$url}\n";
            }

            return trim($output);
        } catch (\Exception $e) {
            return '获取表情包列表失败：' . $e->getMessage();
        }
    }

    protected function searchEmojis(string $query, int $limit): string
    {
        try {
            $emojis = Emoji::where('emoji_name', 'LIKE', '%' . $query . '%')
                ->orderBy('created_at', 'desc')
                ->take($limit)
                ->get();

            if ($emojis->isEmpty()) {
                return "未找到包含「{$query}」的表情包。";
            }

            $output = "搜索结果「{$query}」（{$emojis->count()} 个）：\n";
            foreach ($emojis as $emoji) {
                $name = $emoji->emoji_name ?? '未命名';
                $output .= "- {$name}: {$emoji->emoji_url}\n";
            }

            return trim($output);
        } catch (\Exception $e) {
            return '搜索表情包失败：' . $e->getMessage();
        }
    }

    protected function sendEmoji(string $name): string
    {
        try {
            $emoji = Emoji::where('emoji_name', 'LIKE', '%' . $name . '%')
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$emoji) {
                return "未找到名为「{$name}」的表情包。可先用 search 搜索相近名称。";
            }

            $emojiName = $emoji->emoji_name ?? '未命名';

            return "要发送的表情包：{$emojiName}，图片地址：{$emoji->emoji_url}\n"
                . '请将图片以 Markdown 图片形式（![表情包](' . $emoji->emoji_url . ')）嵌入你的回复正文中发给对方，不要添加多余解释。';
        } catch (\Exception $e) {
            return '获取表情包失败：' . $e->getMessage();
        }
    }

    protected function popularEmojis(int $limit): string
    {
        try {
            $emojis = Emoji::orderBy('created_at', 'desc')
                ->take($limit)
                ->get();

            if ($emojis->isEmpty()) {
                return '暂无表情包。';
            }

            $output = "最近上传的表情包（{$emojis->count()} 个）：\n";
            foreach ($emojis as $emoji) {
                $name = $emoji->emoji_name ?? '未命名';
                $output .= "- {$name}: {$emoji->emoji_url}\n";
            }

            return trim($output);
        } catch (\Exception $e) {
            return '获取热门表情包失败：' . $e->getMessage();
        }
    }
}
