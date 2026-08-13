<?php

namespace Zephyrisle\FlarumZaiBot\Service\Tool;

use Zephyrisle\FlarumZaiBot\Service\MemoryService;

/**
 * Agent 原生工具：recall_long_term_memory
 *
 * 按查询文本召回用户的长期记忆（混合检索：BM25 关键词 + 向量语义融合）。
 * 召回命中会强化记忆（重要度 +1、刷新最近访问时间）。
 */
class RecallMemoryTool implements ToolInterface
{
    public function __construct(
        protected MemoryService $memory,
        protected ?int $userId = null
    ) {}

    public function getName(): string
    {
        return 'recall_long_term_memory';
    }

    public function getDescription(): string
    {
        return '召回用户的长期记忆。当你需要了解与用户有关的长期信息（用户说过的话、偏好、之前讨论过的话题、重要事件）时调用。返回相关的记忆内容、重要度与来源。';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => '要回忆的内容关键词或问题，例如"用户喜欢什么运动"、"用户上次提到的旅行计划"。',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => '返回的记忆条数上限（1-10，默认 5）。',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $args): string
    {
        $query = trim((string) ($args['query'] ?? ''));
        $limit = max(1, min(10, (int) ($args['limit'] ?? 5)));

        if ($query === '') {
            return '请提供要回忆的内容关键词。';
        }

        if (!$this->userId) {
            return '无法识别用户，无法召回记忆。';
        }

        if (!$this->memory->isAvailable()) {
            return '记忆系统未启用（未配置 pgvector 数据库）。';
        }

        $memories = $this->memory->recall($this->userId, $query, $limit);

        if (empty($memories)) {
            return "未找到与「{$query}」相关的记忆。";
        }

        $lines = [];
        foreach ($memories as $mem) {
            $importance = $mem['importance'] ?? 0;
            $level = match (true) {
                $importance >= 6 => '重要',
                $importance >= 3 => '较重要',
                default => '一般',
            };
            $line = "- [{$level}] " . ($mem['content'] ?? '');
            if (!empty($mem['source_text'])) {
                $line .= "（来源：{$mem['source_text']}）";
            }
            $lines[] = $line;
        }

        return "相关记忆（共 " . count($memories) . " 条）：\n" . implode("\n", $lines);
    }
}
