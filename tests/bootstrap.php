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

// Stub for ramon/stickers (optional integration, not installed here) so the
// sticker tools can be unit-tested. Skipped when the real extension is present.
if (!class_exists(\Ramon\Stickers\Models\Sticker::class)) {
    require_once __DIR__ . '/Stubs/stickers.php';
}

// Mirrors ram0ng1/stickers' stickers table (no timestamps — Flarum
// AbstractModel turns those off).
Capsule::schema()->create('stickers', function ($table) {
    $table->increments('id');
    $table->string('category')->nullable();
    $table->string('category_name')->nullable();
    $table->string('title')->nullable();
    $table->string('text_to_replace')->nullable();
    $table->string('path');
});

// Stub for fof/upload (optional integration, not installed here) so the
// vision image proxy can be unit-tested. Skipped when the real extension is present.
if (!class_exists(\FoF\Upload\File::class)) {
    require_once __DIR__ . '/Stubs/fof_upload_file.php';
}

// Mirrors FriendsOfFlarum/upload's fof_upload_files table (1.x: uuid column).
Capsule::schema()->create('fof_upload_files', function ($table) {
    $table->increments('id');
    $table->string('uuid')->nullable()->index();
    $table->string('base_name')->nullable();
    $table->string('path')->nullable();
    $table->string('type')->nullable();
    $table->unsignedBigInteger('size')->default(0);
    $table->boolean('hidden')->default(false);
    $table->string('url')->nullable();
    $table->timestamps();
});

// Context injection event log: mirrors the bot_context_events table.
Capsule::schema()->create('bot_context_events', function ($table) {
    $table->increments('id');
    $table->unsignedInteger('discussion_id')->nullable();
    $table->unsignedInteger('post_id')->nullable();
    $table->unsignedInteger('user_id')->nullable();
    $table->string('type', 50);
    $table->text('description');
    $table->timestamps();
});

// Schema required by the models under test.
Capsule::schema()->create('bot_affinities', function ($table) {
    $table->increments('id');
    $table->integer('user_id')->unsigned()->unique();
    $table->integer('total_score')->default(0);
    $table->integer('trust')->default(0);
    $table->integer('intimacy')->default(0);
    $table->json('emotions')->nullable();
    $table->text('attitude')->nullable();
    $table->text('relationship')->nullable();
    $table->boolean('blacklisted')->default(false);
    $table->integer('interaction_count')->default(0);
    $table->dateTime('last_interaction_at')->nullable();
    $table->timestamps();
});

// Relationship network (stable identity/aliases/boundaries/pending observations).
Capsule::schema()->create('bot_relations', function ($table) {
    $table->increments('id');
    $table->integer('user_id')->unsigned()->unique();
    $table->text('identity')->nullable();
    $table->json('aliases')->nullable();
    $table->text('group_profile')->nullable();
    $table->json('boundaries')->nullable();
    $table->json('pending_observations')->nullable();
    $table->timestamps();
});

// Expression learning rules (pending/active/disabled).
Capsule::schema()->create('bot_expressions', function ($table) {
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
