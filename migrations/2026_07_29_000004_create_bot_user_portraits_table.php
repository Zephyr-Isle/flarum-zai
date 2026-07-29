<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable(
    'bot_user_portraits',
    function (Blueprint $table) {
        $table->increments('id');
        $table->integer('user_id')->unsigned()->unique();
        $table->text('summary')->nullable();
        $table->json('traits')->nullable();
        $table->timestamps();

        $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
    }
);
