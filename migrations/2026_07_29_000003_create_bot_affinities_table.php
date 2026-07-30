<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable(
    'bot_affinities',
    function (Blueprint $table) {
        $table->increments('id');
        $table->integer('user_id')->unsigned()->unique();
        $table->integer('total_score')->default(0);
        $table->integer('interaction_count')->default(0);
        $table->dateTime('last_interaction_at')->nullable();
        $table->timestamps();

        $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
    }
);
