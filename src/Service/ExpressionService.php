<?php

namespace Zephyrisle\FlarumZaiBot\Service;

use Zephyrisle\FlarumZaiBot\Model\BotExpression;

/**
 * 表达学习服务：
 *   - 存储 AI 提交的表达规则（一律先进入待审核）
 *   - 审核：通过（启用）/ 禁用 / 删除
 *   - 注入：仅已启用且作用域匹配的规则进入回复
 *   - 使用统计：由 AI 的 [ExprUsed] 上报递增（管理员只读）
 */
class ExpressionService
{
    public function storePending(array $data): BotExpression
    {
        $expression = new BotExpression();
        $expression->name = trim((string) ($data['name'] ?? ''));
        $expression->status = BotExpression::STATUS_PENDING;
        $sourceType = trim((string) ($data['source_type'] ?? ''));
        $expression->source_type = $sourceType !== '' ? $sourceType : 'manual';
        $expression->situation = trim((string) ($data['situation'] ?? '')) !== '' ? trim((string) $data['situation']) : null;
        $expression->template = trim((string) ($data['template'] ?? ''));
        $expression->syntax = trim((string) ($data['syntax'] ?? '')) !== '' ? trim((string) $data['syntax']) : null;
        $expression->recall_tags = $this->cleanStringArray($data['recall_tags'] ?? null);
        $expression->scope = $this->cleanScope($data['scope'] ?? null);
        $expression->evidence = $this->cleanEvidence($data['evidence'] ?? null);
        $expression->use_count = 0;
        $expression->save();

        return $expression;
    }

    public function list(?string $status, string $q = '', int $page = 1, int $limit = 20): array
    {
        $query = BotExpression::query();

        if (in_array($status, [
            BotExpression::STATUS_PENDING,
            BotExpression::STATUS_ACTIVE,
            BotExpression::STATUS_DISABLED,
        ], true)) {
            $query->where('status', $status);
        }

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('template', 'like', "%{$q}%")
                    ->orWhere('situation', 'like', "%{$q}%");
            });
        }

        $total = (clone $query)->count();
        $items = $query->orderByDesc('updated_at')
            ->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'counts' => [
                BotExpression::STATUS_PENDING => BotExpression::where('status', BotExpression::STATUS_PENDING)->count(),
                BotExpression::STATUS_ACTIVE => BotExpression::where('status', BotExpression::STATUS_ACTIVE)->count(),
                BotExpression::STATUS_DISABLED => BotExpression::where('status', BotExpression::STATUS_DISABLED)->count(),
            ],
        ];
    }

    public function approve(int $id): bool
    {
        $expression = BotExpression::find($id);
        if (!$expression) {
            return false;
        }

        $expression->status = BotExpression::STATUS_ACTIVE;
        $expression->save();

        return true;
    }

    public function disable(int $id): bool
    {
        $expression = BotExpression::find($id);
        if (!$expression) {
            return false;
        }

        $expression->status = BotExpression::STATUS_DISABLED;
        $expression->save();

        return true;
    }

    public function delete(int $id): bool
    {
        $expression = BotExpression::find($id);
        if (!$expression) {
            return false;
        }

        $expression->delete();

        return true;
    }

    public function updateFields(int $id, array $fields): bool
    {
        $expression = BotExpression::find($id);
        if (!$expression) {
            return false;
        }

        foreach (['name', 'situation', 'template', 'syntax'] as $key) {
            if (array_key_exists($key, $fields)) {
                $value = trim((string) $fields[$key]);
                $expression->{$key} = $value !== '' ? $value : null;
            }
        }

        if (array_key_exists('recall_tags', $fields)) {
            $expression->recall_tags = $this->cleanStringArray($fields['recall_tags']);
        }

        if (array_key_exists('scope', $fields)) {
            $expression->scope = $this->cleanScope($fields['scope']);
        }

        $expression->save();

        return true;
    }

    /**
     * 提取当前会话可用的已启用规则（作用域匹配）。
     */
    public function activeRules(string $channel, ?int $userId, ?int $discussionId): array
    {
        return BotExpression::where('status', BotExpression::STATUS_ACTIVE)
            ->orderByDesc('use_count')
            ->get()
            ->filter(fn (BotExpression $e) => $e->matchesScope($channel, $userId, $discussionId))
            ->values()
            ->all();
    }

    /**
     * 构建注入提示词的表达风格库文本（只含名称+情境+模板，不暴露统计等元数据）。
     */
    public function buildInjectionText(array $rules): string
    {
        if ($rules === []) {
            return '';
        }

        $lines = ['以下为用户社区中已确认的表达风格，仅供你在情境匹配时自然借鉴"怎么说"，切勿生硬照搬或刻意使用：'];

        foreach ($rules as $rule) {
            $line = "- 名称：{$rule->name}";
            if (!empty($rule->situation)) {
                $line .= "（情境：{$rule->situation}）";
            }
            $line .= "\n  表达：{$rule->template}";
            if (!empty($rule->syntax)) {
                $line .= "\n  句法：{$rule->syntax}";
            }
            $lines[] = $line;
        }

        $lines[] = '使用某条规则后，请在秘密评估中追加 [ExprUsed: 规则名称]（可多个），但不要在本提示词中出现该标记。';

        return "【表达风格库】\n" . implode("\n", $lines);
    }

    /**
     * 上报规则使用次数（由 [ExprUsed] 解析调用）。
     */
    public function recordUsage(array $names): void
    {
        $names = array_values(array_unique(array_filter(array_map(
            fn ($n) => trim((string) $n),
            $names
        ))));

        if ($names === []) {
            return;
        }

        $rules = BotExpression::whereIn('name', $names)
            ->where('status', BotExpression::STATUS_ACTIVE)
            ->get();

        foreach ($rules as $rule) {
            $rule->recordUse();
        }
    }

    protected function cleanStringArray($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($v) => trim((string) $v),
            $value
        ))));
    }

    protected function cleanScope($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $scope = [];
        foreach (['users', 'discussions', 'channels'] as $key) {
            if (isset($value[$key]) && is_array($value[$key])) {
                $clean = array_values(array_unique(array_filter(array_map(
                    fn ($v) => trim((string) $v),
                    $value[$key]
                ))));
                if ($clean !== []) {
                    $scope[$key] = $clean;
                }
            }
        }

        return $scope;
    }

    protected function cleanEvidence($value): array
    {
        if (is_string($value)) {
            $value = ['quote' => trim($value)];
        }

        if (!is_array($value)) {
            return [];
        }

        $evidence = [];
        if (isset($value['quote'])) {
            $evidence['quote'] = trim((string) $value['quote']);
        }
        if (isset($value['source'])) {
            $evidence['source'] = trim((string) $value['source']);
        }

        return array_filter($evidence, fn ($v) => $v !== '');
    }
}
