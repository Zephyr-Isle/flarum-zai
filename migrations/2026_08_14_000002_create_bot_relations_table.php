<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 关系网：维护每个用户的稳定身份、别名、群资料（社区档案）备注、
 * 边界备注与待确认观察。
 * 关系事实属于"是什么/为什么"，与表达学习（只保存"怎么说"）分离。
 */
return Migration::createTable(
    'bot_relations',
    function (Blueprint $table) {
        $table->increments('id');
        $table->integer('user_id')->unsigned()->unique();
        $table->text('identity')->nullable();
        $table->json('aliases')->nullable();
        $table->text('group_profile')->nullable();
        $table->json('boundaries')->nullable();
        $table->json('pending_observations')->nullable();
        $table->timestamps();

        $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
    }
);
