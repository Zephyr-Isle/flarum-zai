<?php

use Flarum\Extend;
use Zephyrisle\FlarumZaiBot\Console\PurgeMemoryCommand;
use Zephyrisle\FlarumZaiBot\Listener\ReplyToMessage;
use Zephyrisle\FlarumZaiBot\Listener\ReplyToPost;
return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/less/admin.less'),

    (new Extend\Event())
        ->listen(Flarum\Post\Event\Posted::class, ReplyToPost::class),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-messages', fn () => [
            (new Extend\Event())
                ->listen(\Flarum\Messages\DialogMessage\Event\Created::class, ReplyToMessage::class),
        ]),

    (new Extend\Settings())
        ->default('flarum-zai-bot.bot_display_name', 'Yuki')
        ->default('flarum-zai-bot.auto_engage', false)
        ->default('flarum-zai-bot.auto_engage_chance', 20),

    (new Extend\Console())
        ->command(PurgeMemoryCommand::class)
        ->schedule(PurgeMemoryCommand::class, function (\Illuminate\Console\Scheduling\Event $event) {
            $event->daily();
        }),

    (new Extend\ServiceProvider())
        ->register(\Zephyrisle\FlarumZaiBot\Providers\BotServiceProvider::class),
];
