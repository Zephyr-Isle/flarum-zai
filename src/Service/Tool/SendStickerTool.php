<?php

namespace Zephyrisle\FlarumZaiBot\Service\Tool;

class SendStickerTool implements ToolInterface
{
    public function getName(): string
    {
        return 'send_sticker';
    }

    public function getDescription(): string
    {
        return '发送一个贴纸到当前讨论中。可以按贴纸ID、贴纸代码（如 :smile:）或搜索关键词发送。调用此工具后，将返回的贴纸代码包含在你的回复内容中即可显示贴纸。';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sticker_id' => [
                    'type' => 'integer',
                    'description' => '贴纸ID（可选，与sticker_code和query三选一）',
                ],
                'sticker_code' => [
                    'type' => 'string',
                    'description' => '贴纸代码（可选，如 ":smile:"，与sticker_id和query三选一）',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => '搜索关键词，按标题查找贴纸（可选，与sticker_id和sticker_code三选一）',
                ],
            ],
        ];
    }

    public function execute(array $args): string
    {
        if (!class_exists(\Ramon\Stickers\Models\Sticker::class)) {
            return '未安装 ramon/stickers 扩展，无法发送贴纸。';
        }

        $sticker = null;

        if (!empty($args['sticker_id'])) {
            $sticker = \Ramon\Stickers\Models\Sticker::find($args['sticker_id']);
        } elseif (!empty($args['sticker_code'])) {
            $sticker = \Ramon\Stickers\Models\Sticker::where('text_to_replace', $args['sticker_code'])->first();
        } elseif (!empty($args['query'])) {
            $sticker = \Ramon\Stickers\Models\Sticker::where('title', 'like', '%' . $args['query'] . '%')->first();
        }

        if (!$sticker) {
            return '未找到匹配的贴纸。请先用 get_stickers 工具查看可用的贴纸。';
        }

        return "贴纸「{$sticker->title}」已准备就绪。请在回复中直接输出贴纸代码 {$sticker->text_to_replace}，系统会自动将代码替换为贴纸图片。";
    }
}
