<?php

use Illuminate\Database\Capsule\Manager as Capsule;

require __DIR__ . '/../vendor/autoload.php';

// Boot Eloquent against an in-memory SQLite database so model-level tests
// (e.g. BotAffinity) can run without a real MySQL/PostgreSQL server.
$capsule = new Capsule();
$capsule->addConnection([
    'driver' => 'sqlite',
    'database' => ':memory:',
    'prefix' => '',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

// Schema required by the models under test.
Capsule::schema()->create('bot_affinities', function ($table) {
    $table->increments('id');
    $table->integer('user_id')->unsigned()->unique();
    $table->integer('total_score')->default(0);
    $table->integer('interaction_count')->default(0);
    $table->dateTime('last_interaction_at')->nullable();
    $table->timestamps();
});

// Minimal users table: ReplyToPost / ReplyToMessage listeners query it to
// detect bot-authored content and skip dispatching wasted reply jobs.
Capsule::schema()->create('users', function ($table) {
    $table->increments('id');
    $table->string('username', 255)->unique();
    $table->string('email', 255);
    $table->string('display_name', 255)->nullable();
    $table->string('password', 255)->nullable();
    $table->dateTime('joined_at')->nullable();
    $table->dateTime('last_seen_at')->nullable();
    $table->boolean('is_email_confirmed')->default(false);
    $table->timestamps();
});

// Core Flarum tables needed when the tests exercise the real User model:
// the message job lazy-loads the author (posts() / groups() relations) and
// Dialog::users() INNER JOINs the users table.
Capsule::schema()->create('posts', function ($table) {
    $table->increments('id');
    $table->unsignedInteger('discussion_id');
    $table->unsignedInteger('user_id')->nullable();
    $table->unsignedInteger('number')->nullable();
    $table->string('type')->default('comment');
    $table->text('content')->nullable();
    $table->timestamps();
});

Capsule::schema()->create('groups', function ($table) {
    $table->increments('id');
    $table->string('name_singular', 100);
    $table->string('name_plural', 100);
    $table->string('color', 7)->nullable();
    $table->string('icon', 100)->nullable();
    $table->boolean('is_hidden')->default(false);
});

Capsule::schema()->create('group_user', function ($table) {
    $table->unsignedInteger('user_id');
    $table->unsignedInteger('group_id');
    $table->primary(['user_id', 'group_id']);
});

// flarum/messages is installed as a dev dependency so GenerateReplyForMessageTest
// can exercise the real DialogMessage / Dialog / Created classes. The schema below
// mirrors the extension's migrations (foreign keys omitted - SQLite does not
// enforce them by default). The `number` column is required by the model's
// `creating` hook, which assigns each message a per-dialog sequence number.
if (class_exists(\Flarum\Messages\DialogMessage::class)) {
    Capsule::schema()->create('dialogs', function ($table) {
        $table->increments('id');
        $table->unsignedInteger('first_message_id')->nullable();
        $table->unsignedInteger('last_message_id')->nullable();
        $table->dateTime('last_message_at')->nullable();
        $table->unsignedInteger('last_message_user_id')->nullable();
        $table->string('type');
        $table->timestamps();
    });

    Capsule::schema()->create('dialog_messages', function ($table) {
        $table->increments('id');
        $table->unsignedInteger('dialog_id');
        $table->unsignedInteger('user_id')->nullable();
        $table->text('content');
        $table->unsignedBigInteger('number')->nullable();
        $table->timestamps();
    });

    Capsule::schema()->create('dialog_user', function ($table) {
        $table->id();
        $table->unsignedInteger('dialog_id');
        $table->unsignedInteger('user_id');
        $table->dateTime('joined_at')->nullable();
        $table->unsignedInteger('last_read_message_id')->default(0);
        $table->dateTime('last_read_at')->nullable();
    });
}
