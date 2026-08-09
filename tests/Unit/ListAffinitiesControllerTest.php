<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use Flarum\Http\ActorReference;
use Flarum\User\User;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Zephyrisle\FlarumZaiBot\Api\Controller\ListAffinitiesController;
use Zephyrisle\FlarumZaiBot\Model\BotAffinity;
use Zephyrisle\FlarumZaiBot\Tests\Unit\Concerns\ResetsBotAffinities;

class ListAffinitiesControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use ResetsBotAffinities;

    protected function setUp(): void
    {
        $this->resetBotAffinities();
    }

    protected function makeRequest(array $queryParams): ServerRequestInterface
    {
        $actor = Mockery::mock(User::class);
        $actor->shouldReceive('assertAdmin')->once();

        $actorReference = new ActorReference();
        $actorReference->setActor($actor);

        $request = Mockery::mock(ServerRequestInterface::class);
        $request->shouldReceive('getAttribute')->with('actorReference')->andReturn($actorReference);
        $request->shouldReceive('getQueryParams')->andReturn($queryParams);

        return $request;
    }

    protected function createAffinities(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $affinity = BotAffinity::getOrCreate(1000 + $i);
            // 给不同分数，便于验证分页与排序
            $affinity->setScore(($i * 7) % 100 - 20);
        }
    }

    public function testReturnsPaginatedItemsWithTotal(): void
    {
        $this->createAffinities(25);

        $response = (new ListAffinitiesController())->handle($this->makeRequest(['page' => '2', 'limit' => '10']));

        $data = json_decode((string) $response->getBody(), true);

        $this->assertCount(10, $data['items']);
        $this->assertSame(25, $data['total']);
        $this->assertSame(2, $data['page']);
        $this->assertSame(10, $data['limit']);
        $this->assertArrayHasKey('total_score', $data['items'][0]);
    }

    public function testDefaultsToFirstPageWithDefaultLimit(): void
    {
        $this->createAffinities(5);

        $response = (new ListAffinitiesController())->handle($this->makeRequest([]));

        $data = json_decode((string) $response->getBody(), true);

        $this->assertCount(5, $data['items']);
        $this->assertSame(1, $data['page']);
        $this->assertSame(20, $data['limit']);
        $this->assertSame(5, $data['total']);
    }

    public function testClampsOutOfRangePageAndLimit(): void
    {
        $this->createAffinities(5);

        $response = (new ListAffinitiesController())->handle($this->makeRequest(['page' => '0', 'limit' => '500']));

        $data = json_decode((string) $response->getBody(), true);

        $this->assertSame(1, $data['page']);
        $this->assertSame(100, $data['limit']);
    }

    public function testLastPageReturnsRemainder(): void
    {
        $this->createAffinities(25);

        // 第 3 页（每页 10 条）应返回剩余的 5 条
        $response = (new ListAffinitiesController())->handle($this->makeRequest(['page' => '3', 'limit' => '10']));

        $data = json_decode((string) $response->getBody(), true);

        $this->assertCount(5, $data['items']);
        $this->assertSame(3, $data['page']);
    }

    public function testReturnsEmptyItemsWhenNoAffinities(): void
    {
        $response = (new ListAffinitiesController())->handle($this->makeRequest(['page' => '3']));

        $data = json_decode((string) $response->getBody(), true);

        $this->assertSame([], $data['items']);
        $this->assertSame(0, $data['total']);
    }

    public function testClampsPageBeyondLastToLastValidPage(): void
    {
        $this->createAffinities(25);

        // 共 25 条、每页 10 条 → 3 页。请求第 99 页应被钳制到第 3 页。
        $response = (new ListAffinitiesController())->handle($this->makeRequest(['page' => '99', 'limit' => '10']));

        $data = json_decode((string) $response->getBody(), true);

        $this->assertSame(3, $data['page']);
        $this->assertCount(5, $data['items']);
        $this->assertSame(25, $data['total']);
    }
}
