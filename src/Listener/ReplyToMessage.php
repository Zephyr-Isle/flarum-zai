<?php

namespace Zephyrisle\FlarumZaiBot\Listener;

use Flarum\Messages\DialogMessage\Event\Created;
use Illuminate\Contracts\Bus\Dispatcher;
use Zephyrisle\FlarumZaiBot\Job\GenerateReplyForMessage;

class ReplyToMessage
{
    public function __construct(
        protected Dispatcher $bus
    ) {}

    public function handle(object $event): void
    {
        if (!($event instanceof Created)) {
            return;
        }

        $this->bus->dispatch(
            new GenerateReplyForMessage($event->message->id)
        );
    }
}
