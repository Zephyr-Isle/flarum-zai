<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 细致好感度系统：
 *   - trust / intimacy  信任度与亲密度（-100 ~ 100）
 *   - emotions          12 维度情感 JSON（joy/trust/fear/surprise/sadness/disgust/anger/
 *                       anticipation/pride/guilt/shame/envy，各 -100 ~ 100，0 为中性）
 *   - attitude          印象描述（秘密评估 Attitude 字段）
 *   - relationship      关系描述（秘密评估 Relationship 字段）
 *   - blacklisted       黑名单熔断标记（好感度过低时自动加入，管理员可手动移除）
 * total_score 保留为“好感度主值（favor）”，与旧代码兼容。
 */
return Migration::addColumns('bot_affinities', [
    'trust' => ['integer', 'default' => 0, 'after' => 'total_score'],
    'intimacy' => ['integer', 'default' => 0, 'after' => 'trust'],
    'emotions' => ['json', 'nullable' => true, 'after' => 'intimacy'],
    'attitude' => ['text', 'nullable' => true, 'after' => 'emotions'],
    'relationship' => ['text', 'nullable' => true, 'after' => 'attitude'],
    'blacklisted' => ['boolean', 'default' => false, 'after' => 'relationship'],
]);
