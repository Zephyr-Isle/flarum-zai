<?php

namespace Zephyrisle\FlarumZaiBot\Service\Tool;

interface ToolInterface
{
    public function getName(): string;

    public function getDescription(): string;

    public function getParameters(): array;

    public function execute(array $args): string;
}
