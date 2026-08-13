<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 讨论上下文事件记录表：每条记录一个论坛事件（帖子撤回/恢复/删除/编辑，
 * 讨论改名/创建/隐藏/恢复/删除），供上下文注入时按讨论聚合展示。
 */
return Migration::createTable(
    'bot_context_events',
    function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('discussion_id')->nullable()->index();
        $table->unsignedInteger('post_id')->nullable();
        $table->unsignedInteger('user_id')->nullable();
        $table->string('type', 50);
        $table->text('description');
        $table->timestamps();
    }
);
