<?php

namespace Zephyrisle\FlarumZaiBot\Service\Tool;

use Zephyrisle\FlarumZaiBot\Service\ExpressionService;

/**
 * 表达学习工具：AI 从允许的对话中归纳"怎么说"（短表达、句法、情境风格）。
 * 新规则一律进入待审核状态，管理员通过后才进入回复。
 * 昵称、账号、关系事实、秘密与长句不得作为表达规则照搬。
 */
class LearnExpressionTool implements ToolInterface
{
    public function __construct(
        protected ExpressionService $expressionService,
        protected string $channel = 'discussion'
    ) {}

    public function getName(): string
    {
        return 'learn_expression';
    }

    public function getDescription(): string
    {
        return '学习用户独特的表达方式。只学习"怎么说"——短表达、句法习惯与情境风格（例如用户生气时爱用"哼"开头、提问时常带"嘛"、爱用叠词）。绝不照搬：昵称、账号、个人关系事实、秘密、长句；表达模板必须是用户原话中的简短片段（不超过10字）。每次生成回复后检查是否需要学习，需要时调用本工具提交待审核规则。';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => '规则名称，例如"生气时的哼开头"、"提问加嘛尾缀"。',
                ],
                'situation' => [
                    'type' => 'string',
                    'description' => '适用情境，例如"用户生气或不满时"、"用户提出问题时"。',
                ],
                'template' => [
                    'type' => 'string',
                    'description' => '表达模板：用户原话中的简短片段（不超过10字），例如"哼"、"嘛"。',
                ],
                'syntax' => [
                    'type' => 'string',
                    'description' => '句法说明，例如"以\'哼\'开头表示不满"、"句尾加\'嘛\'表疑问与撒娇"。',
                ],
                'recall_tags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => '召回标签（可选），例如 ["生气", "疑问", "撒娇"]。',
                ],
                'scope' => [
                    'type' => 'object',
                    'properties' => [
                        'users' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '仅对指定用户 ID 生效（可选）'],
                        'discussions' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '仅对指定讨论 ID 生效（可选）'],
                        'channels' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '仅对指定渠道生效：discussion / private_message（可选）'],
                    ],
                    'description' => '适用边界（可选，留空则全局适用）。',
                ],
                'evidence' => [
                    'type' => 'string',
                    'description' => '证据：触发学习的用户原话简短引用（只读展示给管理员核验，不改动）。',
                ],
            ],
            'required' => ['name', 'template', 'evidence'],
        ];
    }

    public function execute(array $args): string
    {
        $name = trim((string) ($args['name'] ?? ''));
        $template = trim((string) ($args['template'] ?? ''));

        if ($name === '' || $template === '') {
            return '名称和表达模板不能为空，放弃学习。';
        }

        if (mb_strlen($template) > 10) {
            return '表达模板超过10字（长句不得照搬），请改用用户原话中的简短片段。';
        }

        $expression = $this->expressionService->storePending([
            'name' => $name,
            'source_type' => $this->channel,
            'situation' => $args['situation'] ?? '',
            'template' => $template,
            'syntax' => $args['syntax'] ?? '',
            'recall_tags' => $args['recall_tags'] ?? [],
            'scope' => $args['scope'] ?? [],
            'evidence' => [
                'quote' => trim((string) ($args['evidence'] ?? '')),
                'source' => $this->channel,
            ],
        ]);

        return "已提交待审核表达规则「{$expression->name}」（模板：{$expression->template}）。规则需管理员审核通过后才会进入回复，请勿在后续回复中自行使用未审核的规则。";
    }

    public function setUserId(int $userId): void
    {
        // 表达规则是社区级共享风格，不绑定单一用户
    }
}
