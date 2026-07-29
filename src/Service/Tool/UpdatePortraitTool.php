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
        return '更新用户画像和好感度。根据对话内容判断用户行为，记录观察并调整好感度。affinity_change为正数加分（用户友善、配合、有趣），负数减分（用户不礼貌、恶意、骚扰）。每次互动都应该调用此工具来记录观察。';
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
        $observations = trim($args['observations'] ?? '');

        if ($change < -5) $change = -5;
        if ($change > 5) $change = 5;

        if (!$this->userId) {
            return '无法识别用户，无法更新画像。';
        }

        if (!$observations) {
            $observations = '无特别观察。';
        }

        $portrait = $this->portraitService->updatePortrait($this->userId, $observations, $change);

        $direction = $change >= 0 ? '+' : '';
        return "用户画像已更新。好感度{$direction}{$change}。当前画像：{$portrait->summary}";
    }

    public function setUserId(int $userId): void
    {
        $this->userId = $userId;
    }
}
