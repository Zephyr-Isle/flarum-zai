<?php

namespace Zephyrisle\FlarumZaiBot\Console;

use Flarum\Console\AbstractCommand;
use Zephyrisle\FlarumZaiBot\Service\Memory\MemoryManager;

class PurgeMemoryCommand extends AbstractCommand
{
    public function __construct(protected MemoryManager $memory)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('zai:purge-memory');
        $this->setDescription('Purge expired bot memory entries.');
    }

    protected function fire(): int
    {
        $count = $this->memory->purgeExpired();
        $this->info("Purged {$count} expired memory entries.");
        return 0;
    }
}
