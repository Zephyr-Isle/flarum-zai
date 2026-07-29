<?php

namespace Zephyrisle\FlarumZaiBot\Console;

use Flarum\Console\AbstractCommand;
use Zephyrisle\FlarumZaiBot\Service\Memory\MemoryManager;

class DecayMemoriesCommand extends AbstractCommand
{
    public function __construct(protected MemoryManager $memory)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('zai:decay')
            ->setDescription('Decay old memories and prune low-importance interaction events.');
    }

    protected function fire(): void
    {
        $count = $this->memory->decayMemories();
        $this->info("Decayed/pruned {$count} memory and event records.");
    }
}
