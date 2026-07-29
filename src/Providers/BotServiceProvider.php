<?php

namespace Zephyrisle\FlarumZaiBot\Providers;

use Flarum\Foundation\AbstractServiceProvider;
use Zephyrisle\FlarumZaiBot\Service\BotAccountManager;
use Zephyrisle\FlarumZaiBot\Service\Memory\MemoryManager;
use Zephyrisle\FlarumZaiBot\Service\Provider\AIProvider;

class BotServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AIProvider::class);
        $this->app->singleton(MemoryManager::class);
        $this->app->singleton(BotAccountManager::class);
    }
}
