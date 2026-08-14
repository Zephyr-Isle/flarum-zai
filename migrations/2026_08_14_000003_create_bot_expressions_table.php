<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 表达学习：归纳"怎么说"（短表达、句法、情境风格）。
 *   - status: pending（待审核，不进入回复）/ active（已启用）/ disabled（已禁用）
 *   - source_type: private_message / discussion / manual（来源）
 *   - situation: 适用情境；template: 表达模板；syntax: 句法说明
 *   - recall_tags: 召回标签；scope: 适用边界 {users, discussions, channels}
 *   - evidence: 证据（用户原话简短引用，只读）
 *   - use_count: 使用统计（只读，由 [ExprUsed] 上报递增）
 * 昵称、账号、关系事实、秘密与长句不得作为表达规则照搬。
 */
return Migration::createTable(
    'bot_expressions',
    function (Blueprint $table) {
        $table->increments('id');
        $table->string('name', 100);
        $table->string('status', 20)->default('pending');
        $table->string('source_type', 20)->default('manual');
        $table->text('situation')->nullable();
        $table->text('template');
        $table->text('syntax')->nullable();
        $table->json('recall_tags')->nullable();
        $table->json('scope')->nullable();
        $table->json('evidence')->nullable();
        $table->integer('use_count')->default(0);
        $table->timestamps();
    }
);
