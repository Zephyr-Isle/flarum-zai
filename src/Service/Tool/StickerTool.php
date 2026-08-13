<?php

namespace Zephyrisle\FlarumZaiBot\Service\Tool;

class StickerTool implements ToolInterface
{
    public function getName(): string
    {
        return 'get_stickers';
    }

    public function getDescription(): string
    {
        return '搜索和查看可用的表情包/贴纸。可以按分类列出所有贴纸，或搜索贴纸名称/代码。返回贴纸的标题、代码（如 :smile:）、文件路径。用户使用代码即可在帖子中插入贴纸。';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'category' => [
                    'type' => 'string',
                    'description' => '贴纸分类名称或分类代码（可选），如 "Memes" 或 "memes"，留空则列出所有分类',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => '搜索关键词（可选），按标题或代码搜索贴纸',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => '返回结果数量（默认20，最大50）',
                ],
            ],
        ];
    }

    public function execute(array $args): string
    {
        if (!class_exists(\Ramon\Stickers\Models\Sticker::class)) {
            return '未安装 ramon/stickers 扩展，无法查看贴纸。';
        }

        $category = trim((string) ($args['category'] ?? ''));
        $query = trim((string) ($args['query'] ?? ''));
        $limit = min(max((int) ($args['limit'] ?? 20), 1), 50);

        $stickerQuery = \Ramon\Stickers\Models\Sticker::query();

        if ($category !== '') {
            // ramon/stickers 的 category 存分类代码（如 "memes"），category_name 存显示名
            // （如 "Memes"）。两种都匹配，AI 无论传入显示名还是代码都能命中。
            $stickerQuery->where(function ($q) use ($category) {
                $q->where('category_name', 'like', "%{$category}%")
                  ->orWhere('category', 'like', "%{$category}%");
            });
        }

        if ($query !== '') {
            $stickerQuery->where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                  ->orWhere('text_to_replace', 'like', "%{$query}%");
            });
        }

        $total = (clone $stickerQuery)->count();

        if ($total === 0) {
            if ($query !== '') {
                return "未找到与「{$query}」相关的贴纸。";
            }
            return '暂无可用的贴纸。';
        }

        $stickers = $stickerQuery->orderBy('id')->take($limit)->get();

        $grouped = [];
        foreach ($stickers as $sticker) {
            $cat = $sticker->category_name ?: ($sticker->category ?: '未分类');
            $grouped[$cat][] = $sticker;
        }

        $output = '';
        foreach ($grouped as $catName => $items) {
            $output .= "【{$catName}】\n";
            foreach ($items as $sticker) {
                $code = $sticker->text_to_replace ?: '（无代码）';
                $title = $sticker->title ?: ($sticker->text_to_replace ?: '（无标题）');
                $output .= "- {$title}：{$code}";
                if ($sticker->path) {
                    $output .= "（{$sticker->path}）";
                }
                $output .= "\n";
            }
        }

        if ($total > $limit) {
            $output .= "\n（共{$total}个，仅显示前{$limit}个）";
        }

        return trim($output);
    }
}
