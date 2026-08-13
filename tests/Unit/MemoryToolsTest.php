<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Zephyrisle\FlarumZaiBot\Service\MemoryService;
use Zephyrisle\FlarumZaiBot\Service\Tool\MemorizeMemoryTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\RecallMemoryTool;

class MemoryToolsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function memory(bool $available = true): MemoryService
    {
        $memory = Mockery::mock(MemoryService::class);
        $memory->shouldReceive('isAvailable')->andReturn($available);

        return $memory;
    }

    public function testRecallReturnsFormattedMemories(): void
    {
        $memory = $this->memory();
        $memory->shouldReceive('recall')
            ->once()
            ->with(7, '用户喜欢什么运动', 3)
            ->andReturn([
                ['content' => '用户喜欢打篮球', 'importance' => 6, 'source_text' => '用户说：我每周都打球'],
                ['content' => '用户上次提到爬山', 'importance' => 2, 'source_text' => null],
            ]);

        $tool = new RecallMemoryTool($memory, 7);

        $this->assertSame('recall_long_term_memory', $tool->getName());

        $result = $tool->execute(['query' => '用户喜欢什么运动', 'limit' => 3]);

        $this->assertStringContainsString('相关记忆（共 2 条）', $result);
        $this->assertStringContainsString('[重要] 用户喜欢打篮球', $result);
        $this->assertStringContainsString('来源：用户说：我每周都打球', $result);
        $this->assertStringContainsString('[一般] 用户上次提到爬山', $result);
    }

    public function testRecallRequiresQuery(): void
    {
        $tool = new RecallMemoryTool($this->memory(), 7);

        $this->assertStringContainsString('请提供要回忆的内容关键词', $tool->execute(['query' => '  ']));
    }

    public function testRecallRequiresUser(): void
    {
        $tool = new RecallMemoryTool($this->memory(), null);

        $this->assertStringContainsString('无法识别用户', $tool->execute(['query' => 'x']));
    }

    public function testRecallWhenMemoryUnavailable(): void
    {
        $tool = new RecallMemoryTool($this->memory(false), 7);

        $this->assertStringContainsString('记忆系统未启用', $tool->execute(['query' => 'x']));
    }

    public function testRecallEmptyResult(): void
    {
        $memory = $this->memory();
        $memory->shouldReceive('recall')->andReturn([]);

        $tool = new RecallMemoryTool($memory, 7);

        $this->assertStringContainsString('未找到与「x」相关的记忆', $tool->execute(['query' => 'x']));
    }

    public function testMemorizeStoresWithOptions(): void
    {
        $memory = $this->memory();
        $memory->shouldReceive('memorize')
            ->once()
            ->with(
                7,
                '用户喜欢喝美式咖啡',
                Mockery::on(fn (array $opts) =>
                    $opts['importance'] === 5
                    && $opts['ttl_days'] === 30
                    && $opts['source_text'] === '用户喜欢喝美式咖啡'
                )
            )
            ->andReturn(true);

        $tool = new MemorizeMemoryTool($memory, 7);

        $this->assertSame('memorize_long_term_memory', $tool->getName());

        $result = $tool->execute(['content' => '用户喜欢喝美式咖啡', 'importance' => 5, 'ttl_days' => 30]);

        $this->assertStringContainsString('已记住（重要度 5，30 天后过期）', $result);
    }

    public function testMemorizeWithoutTtl(): void
    {
        $memory = $this->memory();
        $memory->shouldReceive('memorize')->once()->withArgs(function ($userId, $content, $opts) {
            return $opts['ttl_days'] === null;
        })->andReturn(true);

        $tool = new MemorizeMemoryTool($memory, 7);

        $this->assertStringContainsString('已记住', $tool->execute(['content' => '用户养了一只猫']));
    }

    public function testMemorizeClampsImportance(): void
    {
        $memory = $this->memory();
        $memory->shouldReceive('memorize')->once()->withArgs(function ($userId, $content, $opts) {
            return $opts['importance'] === 10;
        })->andReturn(true);

        $tool = new MemorizeMemoryTool($memory, 7);

        $tool->execute(['content' => 'x', 'importance' => 99]);
    }

    public function testMemorizeRequiresContent(): void
    {
        $tool = new MemorizeMemoryTool($this->memory(), 7);

        $this->assertStringContainsString('请提供要记住的内容', $tool->execute(['content' => '']));
    }

    public function testMemorizeRequiresUser(): void
    {
        $tool = new MemorizeMemoryTool($this->memory(), null);

        $this->assertStringContainsString('无法识别用户', $tool->execute(['content' => 'x']));
    }

    public function testMemorizeFailure(): void
    {
        $memory = $this->memory();
        $memory->shouldReceive('memorize')->andReturn(false);

        $tool = new MemorizeMemoryTool($memory, 7);

        $this->assertStringContainsString('记忆写入失败', $tool->execute(['content' => 'x']));
    }
}
