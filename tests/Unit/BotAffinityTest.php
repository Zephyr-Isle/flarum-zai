<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zephyrisle\FlarumZaiBot\Model\BotAffinity;
use Zephyrisle\FlarumZaiBot\Tests\Unit\Concerns\ResetsBotAffinities;

class BotAffinityTest extends TestCase
{
    use ResetsBotAffinities;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetBotAffinities();
    }

    public function testGetOrCreateCreatesRecordForNewUser(): void
    {
        $affinity = BotAffinity::getOrCreate(42);

        $this->assertNotNull($affinity->id);
        $this->assertSame(42, (int) $affinity->user_id);
        $this->assertSame(0, (int) $affinity->total_score);
        $this->assertSame(0, (int) $affinity->interaction_count);
        $this->assertSame(1, BotAffinity::count());
    }

    public function testGetOrCreateReusesExistingRecord(): void
    {
        $first = BotAffinity::getOrCreate(7);
        $second = BotAffinity::getOrCreate(7);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, BotAffinity::count());
    }

    public function testAdjustScoreAccumulatesDeltaAndClampsToBounds(): void
    {
        $affinity = BotAffinity::getOrCreate(1);

        $affinity->adjustScore(10);
        $this->assertSame(10, (int) $affinity->total_score);
        $this->assertSame(1, (int) $affinity->interaction_count);
        $this->assertNotNull($affinity->last_interaction_at);

        $firstTouch = $affinity->last_interaction_at?->timestamp;

        // 10 + 100 = 110, clamped to the 100 upper bound
        $affinity->adjustScore(100);
        $this->assertSame(100, (int) $affinity->total_score);
        $this->assertGreaterThanOrEqual($firstTouch, $affinity->last_interaction_at?->timestamp);

        // 100 - 250 = -150, clamped to the -100 lower bound
        $affinity->adjustScore(-250);
        $this->assertSame(-100, (int) $affinity->total_score);
        $this->assertSame(3, (int) $affinity->interaction_count);
    }

    public function testAdjustScoreWithNegativeDelta(): void
    {
        $affinity = BotAffinity::getOrCreate(2);

        $affinity->adjustScore(-20);
        $this->assertSame(-20, (int) $affinity->total_score);
    }

    public function testSetScoreSetsAbsoluteValueAndClamps(): void
    {
        $affinity = BotAffinity::getOrCreate(3);

        $affinity->adjustScore(20);
        $affinity->setScore(45);
        $this->assertSame(45, (int) $affinity->total_score);

        $affinity->setScore(999);
        $this->assertSame(100, (int) $affinity->total_score);

        $affinity->setScore(-999);
        $this->assertSame(-100, (int) $affinity->total_score);
        $this->assertSame(4, (int) $affinity->interaction_count);
    }

    public function testUpdatesPersistToDatabase(): void
    {
        BotAffinity::getOrCreate(9)->adjustScore(15);
        BotAffinity::getOrCreate(9)->setScore(30);

        $fresh = BotAffinity::where('user_id', 9)->first();

        $this->assertNotNull($fresh);
        $this->assertSame(30, (int) $fresh->total_score);
        $this->assertSame(2, (int) $fresh->interaction_count);
        $this->assertNotNull($fresh->last_interaction_at);
    }
}
