<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('bot_memories', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->unsigned();
            $table->text('content');
            $table->text('created_at');

            $table->index('user_id');
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('bot_memories');
    },
];
