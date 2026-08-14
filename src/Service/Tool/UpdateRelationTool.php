<?php

namespace Zephyrisle\FlarumZaiBot\Service\Tool;

use Zephyrisle\FlarumZaiBot\Service\RelationService;

/**
 * 关系网工具：AI 维护与用户的长期稳定关系信息（身份/别名/群资料/边界/待确认观察）。
 * 只记录长期稳定的事实；临时话题与情绪交给记忆工具；
 * 关系事实属于"是什么"，与表达学习（"怎么说"）分离。
 */
class UpdateRelationTool implements ToolInterface
{
    public function __construct(
        protected RelationService $relationService,
        protected ?int $userId = null
    ) {}

    public function getName(): string
    {
        return 'update_relation_network';
    }

    public function getDescription(): string
    {
        return '维护与当前用户的关系网：稳定身份、别名、社区档案备注、边界备注与待确认观察。只记录长期稳定、反复出现的事实（例如用户身份、常用称呼、明确的互动边界）；一次性话题、临时情绪或短期状态请使用记忆工具。不记录账号密码、家庭隐私等敏感信息。不确定的事实放入待确认观察（pending_observations），由管理员确认后再落地。';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'identity' => [
                    'type' => 'string',
                    'description' => '稳定身份描述（可选，整体覆盖）：长期稳定的身份认知，例如"论坛管理员，精通 PHP"。',
                ],
                'aliases' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => '别名（可选，整体覆盖）：用户可接受的称呼、常用昵称，例如 ["小雪"]。',
                ],
                'group_profile' => [
                    'type' => 'string',
                    'description' => '社区档案备注（可选，整体覆盖）：用户在社区中的角色、常出没板块、群聊风格等。',
                ],
                'boundaries' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => '边界备注（可选，整体覆盖）：用户明确表达的互动边界，例如 ["不喜欢被评价身材"]。',
                ],
                'pending_observations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'observation' => ['type' => 'string', 'description' => '观察内容'],
                            'field' => ['type' => 'string', 'description' => '确认后归属字段：identity / group_profile'],
                        ],
                        'required' => ['observation'],
                    ],
                    'description' => '待确认观察（可选，追加）：不确定但值得留存的观察，管理员确认后才会写入正式字段。',
                ],
            ],
        ];
    }

    public function execute(array $args): string
    {
        if (!$this->userId) {
            return '无法识别用户，无法更新关系网。';
        }

        $relation = $this->relationService->getOrCreate($this->userId);

        $fields = [];
        if (isset($args['identity'])) {
            $fields['identity'] = $args['identity'];
        }
        if (isset($args['aliases']) && is_array($args['aliases'])) {
            $fields['aliases'] = $args['aliases'];
        }
        if (isset($args['group_profile'])) {
            $fields['group_profile'] = $args['group_profile'];
        }
        if (isset($args['boundaries']) && is_array($args['boundaries'])) {
            $fields['boundaries'] = $args['boundaries'];
        }

        if ($fields !== []) {
            $relation->updateFields($fields);
        }

        $added = 0;
        if (isset($args['pending_observations']) && is_array($args['pending_observations'])) {
            foreach ($args['pending_observations'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $observation = trim((string) ($item['observation'] ?? ''));
                if ($observation === '') {
                    continue;
                }
                $field = isset($item['field']) && in_array($item['field'], ['identity', 'group_profile'], true)
                    ? $item['field']
                    : 'identity';
                $relation->addPendingObservation($observation, '', $field);
                $added++;
            }
        }

        $parts = [];
        if (!empty($relation->identity)) {
            $parts[] = "身份：{$relation->identity}";
        }
        if (!empty($relation->aliases)) {
            $parts[] = '别名：' . implode('、', (array) $relation->aliases);
        }
        if (!empty($relation->group_profile)) {
            $parts[] = "档案：{$relation->group_profile}";
        }
        if (!empty($relation->boundaries)) {
            $parts[] = '边界：' . implode('；', (array) $relation->boundaries);
        }

        $summary = $parts === [] ? '（暂无内容）' : implode('；', $parts);
        $pendingNote = $added > 0 ? "，新增待确认观察 {$added} 条（需管理员确认后落地）" : '';

        return "关系网已更新{$pendingNote}。当前：{$summary}";
    }

    public function setUserId(int $userId): void
    {
        $this->userId = $userId;
    }
}
