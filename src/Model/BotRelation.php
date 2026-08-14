<?php

namespace Zephyrisle\FlarumZaiBot\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;

/**
 * 关系网：维护稳定身份、别名、群资料（社区档案）备注、边界备注与待确认观察。
 * 与好感度（情感量）不同，这里存的是长期稳定的事实性关系信息。
 */
class BotRelation extends AbstractModel
{
    protected $table = 'bot_relations';

    public $timestamps = true;

    protected $casts = [
        'aliases' => 'array',
        'boundaries' => 'array',
        'pending_observations' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function getOrCreate(int $userId): self
    {
        $relation = static::where('user_id', $userId)->first();

        if (!$relation) {
            try {
                $relation = new static();
                $relation->user_id = $userId;
                $relation->aliases = [];
                $relation->boundaries = [];
                $relation->pending_observations = [];
                $relation->save();
            } catch (\Illuminate\Database\QueryException $e) {
                // 队列并发时另一个任务可能已抢先创建成功（user_id 唯一约束），改为复用现有记录
                $relation = static::where('user_id', $userId)->first();
            }
        }

        // 极端兜底：插入失败且重查仍为空时，返回一个带 user_id 的未保存实例
        if (!$relation) {
            $relation = new static();
            $relation->user_id = $userId;
        }

        return $relation;
    }

    /**
     * 更新关系网字段（身份/别名/群资料/边界备注整体覆盖）。
     */
    public function updateFields(array $fields): void
    {
        if (array_key_exists('identity', $fields)) {
            $this->identity = trim((string) $fields['identity']) !== '' ? trim((string) $fields['identity']) : null;
        }

        if (array_key_exists('aliases', $fields) && is_array($fields['aliases'])) {
            $aliases = array_values(array_unique(array_filter(array_map(
                fn ($a) => trim((string) $a),
                $fields['aliases']
            ))));
            $this->aliases = $aliases;
        }

        if (array_key_exists('group_profile', $fields)) {
            $this->group_profile = trim((string) $fields['group_profile']) !== '' ? trim((string) $fields['group_profile']) : null;
        }

        if (array_key_exists('boundaries', $fields) && is_array($fields['boundaries'])) {
            $boundaries = [];
            foreach ($fields['boundaries'] as $note) {
                $note = trim((string) $note);
                if ($note !== '') {
                    $boundaries[] = $note;
                }
            }
            $this->boundaries = $boundaries;
        }

        $this->save();
    }

    /**
     * 添加一条边界备注（如"不要讨论其家庭隐私"）。
     */
    public function addBoundary(string $note): void
    {
        $note = trim($note);
        if ($note === '') {
            return;
        }

        $boundaries = $this->boundaries ?? [];
        $boundaries[] = $note;
        $this->boundaries = array_values(array_unique($boundaries));
        $this->save();
    }

    /**
     * 添加一条待确认观察（AI 不确定、需管理员确认后才落地的事实）。
     *
     * @param string $field 确认后归属字段：identity / group_profile
     */
    public function addPendingObservation(string $observation, string $context = '', string $field = 'identity'): void
    {
        $observation = trim($observation);
        if ($observation === '') {
            return;
        }

        if (!in_array($field, ['identity', 'group_profile'], true)) {
            $field = 'identity';
        }

        $pending = $this->pending_observations ?? [];
        $pending[] = [
            'observation' => $observation,
            'context' => trim($context),
            'field' => $field,
            'created_at' => \Carbon\Carbon::now()->toDateTimeString(),
        ];

        // 上限 50 条，超出移除最旧的
        if (count($pending) > 50) {
            array_shift($pending);
        }

        $this->pending_observations = $pending;
        $this->save();
    }

    /**
     * 确认待确认观察（按索引）：写入对应字段后从待确认列表移除。
     */
    public function confirmObservation(int $index): bool
    {
        $pending = $this->pending_observations ?? [];
        if (!isset($pending[$index])) {
            return false;
        }

        $item = $pending[$index];
        $text = trim((string) ($item['observation'] ?? ''));

        if ($text !== '') {
            $field = $item['field'] ?? 'identity';
            if ($field === 'group_profile') {
                $this->group_profile = $this->appendText($this->group_profile, $text);
            } else {
                $this->identity = $this->appendText($this->identity, $text);
            }
        }

        array_splice($pending, $index, 1);
        $this->pending_observations = $pending;
        $this->save();

        return true;
    }

    /**
     * 驳回待确认观察（按索引）：直接移除。
     */
    public function rejectObservation(int $index): bool
    {
        $pending = $this->pending_observations ?? [];
        if (!isset($pending[$index])) {
            return false;
        }

        array_splice($pending, $index, 1);
        $this->pending_observations = $pending;
        $this->save();

        return true;
    }

    protected function appendText(?string $target, string $text): string
    {
        $target = trim((string) $target);
        if ($target === '') {
            return $text;
        }

        return $target . '；' . $text;
    }
}
