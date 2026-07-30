<?php

use Flarum\Extend;
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
        ->default('flarum-zai-bot.personality', 'friendly')
        ->default('flarum-zai-bot.bot_display_name', 'Yuki')
        ->default('flarum-zai-bot.timezone', 'Asia/Shanghai')
        ->default('flarum-zai-bot.openweather_city', 'Beijing'),

    (new Extend\Model(\Zephyrisle\FlarumZaiBot\Model\BotAffinity::class)),

    (new Extend\Model(\Zephyrisle\FlarumZaiBot\Model\UserPortrait::class)),

    (new Extend\Locales(__DIR__ . '/locale')),

    (new Extend\Routes('api'))
        ->get('/zai-bot/affinities', 'zai-bot.affinities', \Zephyrisle\FlarumZaiBot\Api\Controller\ListAffinitiesController::class)
        ->post('/zai-bot/test-api', 'zai-bot.test-api', \Zephyrisle\FlarumZaiBot\Api\Controller\TestApiController::class),
];
