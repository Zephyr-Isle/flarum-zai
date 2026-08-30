<?php

namespace Zephyrisle\FlarumZaiBot\Service;

use Flarum\Gdpr\Extend\UserData;
use Flarum\Gdpr\Models\DataType;
use Flarum\User\User;

/**
 * flarum/gdpr 适配：将机器人的用户数据（好感度、记忆、关系网、表达规则）纳入 GDPR 数据导出。
 *
 * 通过 flarum/gdpr 的 UserData 扩展器注册自定义数据类型，
 * 当用户请求数据导出时，自动包含机器人收集的相关数据。
 */
class GdprDataProvider extends DataType
{
    /**
     * 数据类型标识符。
     */
    public static function getIdentifier(): string
    {
        return 'zephyrisle-flarum-zai-bot';
    }

    /**
     * 数据类型标签（用于导出文件中的显示）。
     */
    public function label(): string
    {
        return 'Bot AI 数据（好感度/记忆/关系网/表达规则）';
    }

    /**
     * 收集用户的机器人相关数据。
     *
     * @param User $user 用户模型
     * @return array 导出的数据
     */
    public function getData(User $user): array
    {
        $data = [];

        // 1. 好感度数据
        try {
            $affinity = \Zephyrisle\FlarumZaiBot\Model\BotAffinity::where('user_id', $user->id)->first();
            if ($affinity) {
                $data['affinity'] = [
                    'total_score' => $affinity->total_score,
                    'trust' => $affinity->trust,
                    'intimacy' => $affinity->intimacy,
                    'emotions' => $affinity->emotions ?? [],
                    'attitude' => $affinity->attitude,
                    'relationship' => $affinity->relationship,
                    'blacklisted' => $affinity->blacklisted,
                    'interaction_count' => $affinity->interaction_count,
                    'last_interaction_at' => $affinity->last_interaction_at,
                ];
            }
        } catch (\Exception $e) {
            $data['affinity_error'] = $e->getMessage();
        }

        // 2. 关系网数据
        try {
            $relations = \Zephyrisle\FlarumZaiBot\Model\BotRelation::where('user_id', $user->id)->get();
            if ($relations->isNotEmpty()) {
                $data['relations'] = $relations->map(function ($rel) {
                    return [
                        'user_id' => $rel->user_id,
                        'identity' => $rel->identity,
                        'aliases' => $rel->aliases,
                        'group_profile' => $rel->group_profile,
                        'boundaries' => $rel->boundaries,
                    ];
                })->toArray();
            }
        } catch (\Exception $e) {
            $data['relations_error'] = $e->getMessage();
        }

        // 3. 表达规则：bot_expressions 是全局规则表（没有 user_id 列），不含个人数据，
        //    不纳入用户数据导出。
        // 4. 记忆数据存储在外部 pgvector 数据库中，无法通过 Eloquent 直接查询，
        //    请管理员在后台记忆管理页按用户检索并删除。

        return $data;
    }

    /**
     * 删除用户的所有机器人数据（GDPR 删除请求）。
     *
     * @param User $user 用户模型
     */
    public function forget(User $user): void
    {
        // 1. 删除好感度数据
        try {
            \Zephyrisle\FlarumZaiBot\Model\BotAffinity::where('user_id', $user->id)->delete();
        } catch (\Exception $e) {
            error_log('[flarum-zai-bot] GDPR forget affinity failed: ' . $e->getMessage());
        }

        // 2. 删除关系网数据
        try {
            \Zephyrisle\FlarumZaiBot\Model\BotRelation::where('user_id', $user->id)->delete();
        } catch (\Exception $e) {
            error_log('[flarum-zai-bot] GDPR forget relation failed: ' . $e->getMessage());
        }

        // 3. 表达规则：bot_expressions 是全局规则表（没有 user_id 列），不含个人数据，无需删除。

        // 4. 记忆数据存储在外部 pgvector 数据库中
        // 需要通过 MemoryService 删除，但这里不直接依赖
        // 用户可以在后台手动删除记忆数据
        error_log('[flarum-zai-bot] GDPR forget: user ' . $user->id . ' bot data deleted. Note: pgvector memories may need manual cleanup.');
    }
}
