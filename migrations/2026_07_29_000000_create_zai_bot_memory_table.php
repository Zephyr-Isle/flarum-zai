<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('zai_bot_memory', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->unsignedInteger('bot_user_id');
    $table->string('type', 50);
    $table->string('key', 100);
    $table->text('data')->nullable();
    $table->timestamp('created_at')->nullable();
    $table->timestamp('expires_at')->nullable();

    $table->unique(['bot_user_id', 'type', 'key']);
    $table->index(['bot_user_id', 'type']);
    $table->index('expires_at');
});
