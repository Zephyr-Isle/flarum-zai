<?php

namespace Zephyrisle\FlarumZaiBot\Service;

use Zephyrisle\FlarumZaiBot\Model\BotRelation;

/**
 * 关系网服务：维护稳定身份、别名、群资料备注、边界备注与待确认观察，
 * 并为每次回复构建关系网摘要注入提示词。
 */
class RelationService
{
    public function getOrCreate(int $userId): BotRelation
    {
        return BotRelation::getOrCreate($userId);
    }

    public function get(int $userId): ?BotRelation
    {
        return BotRelation::where('user_id', $userId)->first();
    }

    /**
     * 构建关系网摘要（供系统消息注入）。无有效内容时返回 null。
     */
    public function buildSummary(int $userId): ?string
    {
        $relation = $this->get($userId);
        if (!$relation) {
            return null;
        }

        $parts = [];

        if (!empty($relation->identity)) {
            $parts[] = "稳定身份：{$relation->identity}";
        }

        if (!empty($relation->aliases)) {
            $parts[] = '别名：' . implode('、', (array) $relation->aliases);
        }

        if (!empty($relation->group_profile)) {
            $parts[] = "社区档案备注：{$relation->group_profile}";
        }

        if (!empty($relation->boundaries)) {
            $parts[] = '边界：' . implode('；', (array) $relation->boundaries);
        }

        if ($parts === []) {
            return null;
        }

        return "【关系网】\n" . implode("\n", $parts) . "\n（关系网是你的长期稳定认知，与好感度情感状态相互独立，除非确认发生变化否则不要轻易修改）";
    }

    /**
     * 添加待确认观察（AI 学习流程入口）。
     *
     * @param string $context 观察来源的上下文说明
     * @param string $field   确认后归属字段：identity / group_profile
     */
    public function addPendingObservation(int $userId, string $observation, string $context = '', string $field = 'identity'): void
    {
        $this->getOrCreate($userId)->addPendingObservation($observation, $context, $field);
    }

    public function confirmObservation(int $userId, int $index): bool
    {
        $relation = $this->get($userId);

        return $relation ? $relation->confirmObservation($index) : false;
    }

    public function rejectObservation(int $userId, int $index): bool
    {
        $relation = $this->get($userId);

        return $relation ? $relation->rejectObservation($index) : false;
    }

    public function update(int $userId, array $fields): BotRelation
    {
        $relation = $this->getOrCreate($userId);
        $relation->updateFields($fields);

        return $relation;
    }
}
