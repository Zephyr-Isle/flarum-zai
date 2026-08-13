<?php

namespace Zephyrisle\FlarumZaiBot\Job\Concerns;

use Flarum\Settings\SettingsRepositoryInterface;
use Zephyrisle\FlarumZaiBot\Service\MemoryService;
use Zephyrisle\FlarumZaiBot\Service\PortraitService;
use Zephyrisle\FlarumZaiBot\Service\Tool\LikeTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\MemorizeMemoryTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\RecallMemoryTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\SearchTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\SendStickerTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\StickerTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\UpdatePortraitTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\UserInfoTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\ViewFileTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\WebSearchTool;

/**
 * 按已安装的扩展与设置构建 AI 可用的工具列表。
 * 只为真正可用的功能注册工具，避免把不可用工具的声明白白发送给模型（浪费 token）。
 */
trait BuildsBotTools
{
    protected function buildBotTools(int $botUserId, ?int $userId, SettingsRepositoryInterface $settings): array
    {
        $tools = [
            new UserInfoTool(),
            new SearchTool(),
            new ViewFileTool(),
            new UpdatePortraitTool(resolve(PortraitService::class), $userId),
        ];

        if (class_exists(\Ramon\Stickers\Models\Sticker::class)) {
            $tools[] = new StickerTool();
            $tools[] = new SendStickerTool();
        }

        if (class_exists(\Flarum\Likes\Event\PostWasLiked::class)) {
            $tools[] = new LikeTool($botUserId);
        }

        if ((bool) $settings->get('flarum-zai-bot.jina_optimization_mode', false)) {
            $tools[] = resolve(WebSearchTool::class);
        }

        // Agent 原生工具：长期记忆的主动召回与写入（记忆系统可用时注册，避免白耗 token）
        try {
            $memory = resolve(MemoryService::class);
            if ($memory->isAvailable()) {
                $tools[] = new RecallMemoryTool($memory, $userId);
                $tools[] = new MemorizeMemoryTool($memory, $userId);
            }
        } catch (\Exception $e) {
        }

        return $tools;
    }
}
