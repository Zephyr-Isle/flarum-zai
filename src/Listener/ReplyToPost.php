<?php

namespace Zephyrisle\FlarumZaiBot\Listener;

use Flarum\Post\Event\Posted;
use Illuminate\Contracts\Bus\Dispatcher;
use Zephyrisle\FlarumZaiBot\Job\GenerateReplyForPost;

class ReplyToPost
{
    public function __construct(
        protected Dispatcher $bus
    ) {}

    public function handle(Posted $event): void
    {
        $this->bus->dispatch(
            new GenerateReplyForPost($event->post->id)
        );
    }
}
