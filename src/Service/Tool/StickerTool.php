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
        return '搜索和查看可用的表情包/贴纸。可以按分类列出所有贴纸，或搜索贴纸名称/代码。用户可以使用贴纸代码（如 :smile:）在帖子中插入。';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'category' => [
                    'type' => 'string',
                    'description' => '贴纸分类名称（可选），留空则列出所有分类',
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

        $category = $args['category'] ?? '';
        $query = $args['query'] ?? '';
        $limit = min((int) ($args['limit'] ?? 20), 50);

        $stickerQuery = \Ramon\Stickers\Models\Sticker::query();

        if ($category) {
            $stickerQuery->where('category', $category);
        }

        if ($query) {
            $stickerQuery->where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                  ->orWhere('text_to_replace', 'like', "%{$query}%");
            });
        }

        $stickers = $stickerQuery->take($limit)->get();

        if ($stickers->isEmpty()) {
            if ($query) {
                return "未找到与「{$query}」相关的贴纸。";
            }
            return '暂无可用的贴纸。';
        }

        $grouped = [];
        foreach ($stickers as $sticker) {
            $cat = $sticker->category_name ?: ($sticker->category ?: '未分类');
            $grouped[$cat][] = $sticker;
        }

        $output = '';
        foreach ($grouped as $catName => $items) {
            $output .= "【{$catName}】\n";
            foreach ($items as $sticker) {
                $code = $sticker->text_to_replace ?: "（无代码）";
                $output .= "- {$sticker->title}：{$code}\n";
            }
        }

        if ($stickers->count() >= $limit) {
            $output .= "\n（仅显示前{$limit}个结果）";
        }

        return trim($output);
    }
}
