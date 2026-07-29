<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTableIfNotExists('zai_user_memories', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('user_id');
    $table->string('key', 100);
    $table->text('value');
    $table->float('importance')->default(0.5);
    $table->timestamp('last_accessed_at')->nullable();
    $table->timestamps();

    $table->unique(['user_id', 'key']);
    $table->index('user_id');
});
