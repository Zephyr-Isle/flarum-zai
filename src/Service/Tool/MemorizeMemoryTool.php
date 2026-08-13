<?php

namespace Zephyrisle\FlarumZaiBot\Service\Tool;

use Zephyrisle\FlarumZaiBot\Service\MemoryService;

/**
 * Agent 原生工具：memorize_long_term_memory
 *
 * 将一条事实写入用户的长期记忆，作为独立记忆原子。
 * 支持设置重要度与存活天数（TTL），并保留来源文本用于核验。
 */
class MemorizeMemoryTool implements ToolInterface
{
    public function __construct(
        protected MemoryService $memory,
        protected ?int $userId = null
    ) {}

    public function getName(): string
    {
        return 'memorize_long_term_memory';
    }

    public function getDescription(): string
    {
        return '写入一条长期记忆。当用户在对话中透露了值得长期记住的事实（偏好、身份信息、重要经历、长期计划等）时调用，以便未来召回。';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'content' => [
                    'type' => 'string',
                    'description' => '要记住的事实内容，独立成条、自包含描述，例如"用户喜欢喝美式咖啡，不喜欢加糖"。',
                ],
                'importance' => [
                    'type' => 'integer',
                    'description' => '重要度 0-10（默认 0）。重要事实（身份、重大事件）给较高值。',
                ],
                'ttl_days' => [
                    'type' => 'integer',
                    'description' => '记忆存活天数（可选）。临时性信息（如"这周末去旅行"）可设置短 TTL；不设置则长期保存。',
                ],
            ],
            'required' => ['content'],
        ];
    }

    public function execute(array $args): string
    {
        $content = trim((string) ($args['content'] ?? ''));
        $importance = max(0, min(10, (int) ($args['importance'] ?? 0)));
        $ttlDays = isset($args['ttl_days']) && $args['ttl_days'] !== '' ? max(1, (int) $args['ttl_days']) : null;

        if ($content === '') {
            return '请提供要记住的内容。';
        }

        if (!$this->userId) {
            return '无法识别用户，无法写入记忆。';
        }

        if (!$this->memory->isAvailable()) {
            return '记忆系统未启用（未配置 pgvector 数据库）。';
        }

        $ok = $this->memory->memorize($this->userId, $content, [
            'importance' => $importance,
            'ttl_days' => $ttlDays,
            'source_text' => $content,
        ]);

        if (!$ok) {
            return '记忆写入失败（请检查 Embedding 配置与数据库连接）。';
        }

        $suffix = $ttlDays !== null ? "，{$ttlDays} 天后过期" : '';
        return "已记住（重要度 {$importance}{$suffix}）。";
    }
}
