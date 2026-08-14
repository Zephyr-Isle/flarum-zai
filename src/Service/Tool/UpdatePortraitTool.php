<?php

namespace Zephyrisle\FlarumZaiBot\Service\Tool;

use Zephyrisle\FlarumZaiBot\Service\PortraitService;

class UpdatePortraitTool implements ToolInterface
{
    public function __construct(
        protected PortraitService $portraitService,
        protected ?int $userId = null
    ) {}

    public function getName(): string
    {
        return 'update_user_portrait';
    }

    public function getDescription(): string
    {
        return '更新用户画像和好感度。根据对话内容判断用户行为，记录观察并调整好感度（及可选的信任度、亲密度）。affinity_change为正数加分（用户友善、配合、有趣），负数减分（用户不礼貌、恶意、骚扰）。每次互动都应该调用此工具来记录观察。';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'affinity_change' => [
                    'type' => 'integer',
                    'description' => '好感度变化值：-5到+5。用户表现好时正数加分，表现差时负数减分。一般情况为0。',
                ],
                'trust_change' => [
                    'type' => 'integer',
                    'description' => '信任度变化值：-5到+5（可选，默认0）。用户言行一致、坦诚、可依赖时加分；欺骗、隐瞒、反复无常时减分。',
                ],
                'intimacy_change' => [
                    'type' => 'integer',
                    'description' => '亲密度变化值：-5到+5（可选，默认0）。用户主动分享私事、敞开心扉、表现出亲近时加分；刻意保持距离、态度生硬时减分。',
                ],
                'observations' => [
                    'type' => 'string',
                    'description' => '对用户的观察和评价，用于构建用户画像。例如"用户对编程很感兴趣，态度友好"、"用户今天情绪低落"等。记录要点：兴趣、性格、态度、当前状态。',
                ],
            ],
            'required' => ['affinity_change', 'observations'],
        ];
    }

    public function execute(array $args): string
    {
        $change = (int) ($args['affinity_change'] ?? 0);
        $trustChange = (int) ($args['trust_change'] ?? 0);
        $intimacyChange = (int) ($args['intimacy_change'] ?? 0);
        $observations = trim($args['observations'] ?? '');

        if ($change < -5) $change = -5;
        if ($change > 5) $change = 5;
        if ($trustChange < -5) $trustChange = -5;
        if ($trustChange > 5) $trustChange = 5;
        if ($intimacyChange < -5) $intimacyChange = -5;
        if ($intimacyChange > 5) $intimacyChange = 5;

        if (!$this->userId) {
            return '无法识别用户，无法更新画像。';
        }

        if (!$observations) {
            $observations = '无特别观察。';
        }

        $portrait = $this->portraitService->updatePortrait($this->userId, $observations, $change, $trustChange, $intimacyChange);

        $direction = $change >= 0 ? '+' : '';
        $extra = '';
        if ($trustChange !== 0 || $intimacyChange !== 0) {
            $extra = '，信任度' . ($trustChange >= 0 ? '+' : '') . $trustChange . '，亲密度' . ($intimacyChange >= 0 ? '+' : '') . $intimacyChange;
        }
        return "用户画像已更新。好感度{$direction}{$change}{$extra}。当前画像：{$portrait->summary}";
    }

    public function setUserId(int $userId): void
    {
        $this->userId = $userId;
    }
}
