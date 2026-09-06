<?php

namespace Zephyrisle\FlarumZaiBot\Service\Tool;

use Zephyrisle\FlarumZaiBot\Service\StreakService;

/**
 * cloudnest「续火花」工具：查询与当前对话用户的互动火花状态。
 */
class StreakTool implements ToolInterface
{
    public function __construct(
        protected StreakService $service,
        protected ?int $userId = null
    ) {}

    public function getName(): string
    {
        return 'streak';
    }

    public function getDescription(): string
    {
        return '查询与当前对话用户的「续火花」互动状态（连续互聊天数、历史最长天数）。可用于回复中自然提及火花。';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'other_user_id' => [
                    'type' => 'integer',
                    'description' => '对方用户 ID（对话上下文中的对方）',
                ],
            ],
            'required' => ['other_user_id'],
        ];
    }

    public function execute(array $args): string
    {
        $otherUserId = (int) ($args['other_user_id'] ?? 0);
        if ($otherUserId <= 0 || !$this->userId) {
            return '缺少有效的用户 ID。';
        }

        $streak = $this->service->readForPair($this->userId, $otherUserId);
        if (!$streak) {
            return '还没有与该用户互聊的火花记录。';
        }

        if ($streak['current_streak'] > 0) {
            $text = "你们当前已连续互聊 {$streak['current_streak']} 天（历史最长 {$streak['best_streak']} 天）";
            if ($streak['last_mutual_date']) {
                $text .= "，最近互聊日：{$streak['last_mutual_date']}";
            }

            return $text . '。';
        }

        return '与该用户的火花尚未点亮。';
    }
}