<?php

use Flarum\Extend;
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
];
