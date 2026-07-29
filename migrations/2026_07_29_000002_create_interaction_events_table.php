<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTableIfNotExists('zai_interaction_events', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('user_id');
    $table->string('type', 50);
    $table->text('summary');
    $table->string('reference_type', 50)->nullable();
    $table->unsignedInteger('reference_id')->nullable();
    $table->float('importance')->default(0.3);
    $table->timestamp('created_at')->nullable();

    $table->index('user_id');
    $table->index('created_at');
});
