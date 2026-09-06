<?php

namespace Zephyrisle\FlarumZaiBot\Job\Concerns;

use Flarum\Settings\SettingsRepositoryInterface;
use Zephyrisle\FlarumZaiBot\Service\ExpressionService;
use Zephyrisle\FlarumZaiBot\Service\MemoryService;
use Zephyrisle\FlarumZaiBot\Service\PortraitService;
use Zephyrisle\FlarumZaiBot\Service\RelationService;
use Zephyrisle\FlarumZaiBot\Service\StreakService;
use Zephyrisle\FlarumZaiBot\Service\Tool\BestAnswerTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\DraftTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\FlagTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\ImageGenTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\KaomojiTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\ProfileTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\UserEmojiTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\VideoGenTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\LearnExpressionTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\LikeTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\MemorizeMemoryTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\RecallMemoryTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\SearchTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\SendStickerTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\StickerTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\StreakTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\UpdatePortraitTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\UpdateRelationTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\UserInfoTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\ViewFileTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\WebSearchTool;

/**
 * 按已安装的扩展与设置构建 AI 可用的工具列表。
 * 只为真正可用的功能注册工具，避免把不可用工具的声明白白发送给模型（浪费 token）。
 */
trait BuildsBotTools
{
    protected function buildBotTools(int $botUserId, ?int $userId, SettingsRepositoryInterface $settings, string $channel = 'discussion'): array
    {
        $tools = [
            new UserInfoTool(),
            new SearchTool(),
            new ViewFileTool(),
            new UpdatePortraitTool(resolve(PortraitService::class), $userId),
        ];

        // 关系网（可在设置中关闭）
        if ((bool) $settings->get('flarum-zai-bot.relation_network_enabled', true)) {
            $tools[] = new UpdateRelationTool(resolve(RelationService::class), $userId);
        }

        // 表达学习（可在设置中关闭）
        if ((bool) $settings->get('flarum-zai-bot.expression_learning_enabled', true)) {
            $tools[] = new LearnExpressionTool(resolve(ExpressionService::class), $channel);
        }

        if (class_exists(\Ramon\Stickers\Models\Sticker::class)) {
            $tools[] = new StickerTool();
            $tools[] = new SendStickerTool();
        }

        if (class_exists(\Flarum\Likes\Event\PostWasLiked::class)) {
            $tools[] = new LikeTool($botUserId);
        }

        // fof/best-answer: 标记最佳回答（该扩展没有 Models 目录，用其事件类探测是否安装）
        if (class_exists(\FoF\BestAnswer\Events\BestAnswerSet::class)) {
            $tools[] = new BestAnswerTool($botUserId);
        }

        // flarum/flags: 举报帖子
        if (class_exists(\Flarum\Flags\Flag::class)) {
            $tools[] = new FlagTool($botUserId);
        }

        // fof/drafts: 草稿管理
        if (class_exists(\FoF\Drafts\Draft::class)) {
            $tools[] = new DraftTool($userId);
        }

        // cloudnest/user-emoji: 用户自定义表情包
        if (class_exists(\CloudNest\Emoji\Emoji::class)) {
            $tools[] = new UserEmojiTool($userId);
        }

        // cloudnest「续火花」：查询与对方的互动火花状态
        if (class_exists(\CloudNest\Emoji\Streak::class)) {
            try {
                $tools[] = new StreakTool(resolve(StreakService::class), $userId);
            } catch (\Exception $e) {
                // 测试环境或依赖缺失时跳过
            }
        }

        // 个人资料管理：修改机器人自己的头像、背景图、简介
        // 注意：Flarum 2.x 没有 Flarum\Http\Client，工具内部直接用 GuzzleHttp\Client；
        // 上传地址用论坛自身的 API 根地址（UrlGenerator），不要用 LLM 的 api_url。
        try {
            $tools[] = new ProfileTool(
                $botUserId,
                new \GuzzleHttp\Client(),
                resolve(\Flarum\Http\UrlGenerator::class)->to('api')->base()
            );
        } catch (\Exception $e) {
            // 测试环境或依赖缺失时跳过
        }

        // 颜文字工具：提供日式文本表情符号
        $tools[] = new KaomojiTool();

        // Agnes AI 图片/视频生成工具
        if (!empty($settings->get('flarum-zai-bot.agnes_api_key', ''))) {
            try {
                $tools[] = new ImageGenTool(new \GuzzleHttp\Client(), $settings);
                $tools[] = new VideoGenTool(new \GuzzleHttp\Client(), $settings);
            } catch (\Exception $e) {
                // 测试环境或依赖缺失时跳过
            }
        }

        if ((bool) $settings->get('flarum-zai-bot.jina_optimization_mode', false)) {
            // 传入机器人用户 ID：内建代理路由仅管理员/机器人可用，工具调用时需携带机器人令牌
            $tools[] = new WebSearchTool($settings, resolve(\GuzzleHttp\Client::class), resolve(\Flarum\Http\UrlGenerator::class), $botUserId);
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
