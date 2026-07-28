<?php

use Flarum\Api\Serializer\UserSerializer;
use Flarum\Extend;
use Zephyrisle\FlarumZaiBot\Listener\ReplyToMessage;
use Zephyrisle\FlarumZaiBot\Listener\ReplyToPost;
use Zephyrisle\FlarumZaiBot\Serializer\BotUserAttributes;

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

    (new Extend\ApiSerializer(UserSerializer::class))
        ->attributes(BotUserAttributes::class),
];
