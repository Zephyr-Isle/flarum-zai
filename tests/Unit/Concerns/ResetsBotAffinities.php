<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit\Concerns;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * SQLite 内存库在整个测试进程内共享，所有会写入 bot_affinities 的测试类
 * 都应在 setUp 中调用 resetBotAffinities()，避免相互污染计数类断言。
 */
trait ResetsBotAffinities
{
    protected function resetBotAffinities(): void
    {
        Capsule::table('bot_affinities')->truncate();
    }
}
