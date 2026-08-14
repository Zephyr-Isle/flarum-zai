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

    public function testTrustAndIntimacyAdjustmentsClampToBounds(): void
    {
        $affinity = BotAffinity::getOrCreate(10);

        $affinity->adjustTrust(40);
        $this->assertSame(40, (int) $affinity->trust);

        $affinity->adjustTrust(200);
        $this->assertSame(100, (int) $affinity->trust);

        $affinity->adjustIntimacy(-150);
        $this->assertSame(-100, (int) $affinity->intimacy);

        $affinity->setTrust(25);
        $this->assertSame(25, (int) $affinity->trust);

        $affinity->setIntimacy(0);
        $this->assertSame(0, (int) $affinity->intimacy);
    }

    public function testEmotionDeltasClampAndIgnoreUnknownKeys(): void
    {
        $affinity = BotAffinity::getOrCreate(11);

        $affinity->applyEmotionDeltas(['joy' => 5, 'anger' => -10, 'nonexistent' => 99]);
        $this->assertSame(5, $affinity->getEmotion('joy'));
        $this->assertSame(-10, $affinity->getEmotion('anger'));
        $this->assertSame(0, $affinity->getEmotion('nonexistent'));

        // 主动代谢：负值抵消旧情绪（-10 + 20 - 15 = -5）
        $affinity->applyEmotionDeltas(['anger' => 20]);
        $affinity->applyEmotionDeltas(['anger' => -15]);
        $this->assertSame(-5, $affinity->getEmotion('anger'));

        // 钳制
        $affinity->applyEmotionDeltas(['joy' => 999]);
        $this->assertSame(100, $affinity->getEmotion('joy'));

        $affinity->setEmotion('shame', 30);
        $this->assertSame(30, $affinity->getEmotion('shame'));
    }

    public function testAttitudeRelationshipAndBlacklist(): void
    {
        $affinity = BotAffinity::getOrCreate(12);

        $affinity->setAttitude('友善热情');
        $this->assertSame('友善热情', $affinity->attitude);

        $affinity->setRelationship('普通朋友');
        $this->assertSame('普通朋友', $affinity->relationship);

        $affinity->blacklist();
        $this->assertTrue((bool) $affinity->blacklisted);

        $affinity->unblacklist();
        $this->assertFalse((bool) $affinity->blacklisted);
    }

    public function testResetClearsAllState(): void
    {
        $affinity = BotAffinity::getOrCreate(13);
        $affinity->adjustScore(50);
        $affinity->setTrust(60);
        $affinity->setIntimacy(70);
        $affinity->applyEmotionDeltas(['joy' => 80]);
        $affinity->setAttitude('亲密');
        $affinity->setRelationship('挚友');
        $affinity->blacklist();

        $affinity->reset();

        $this->assertSame(0, (int) $affinity->total_score);
        $this->assertSame(0, (int) $affinity->trust);
        $this->assertSame(0, (int) $affinity->intimacy);
        $this->assertSame(0, $affinity->getEmotion('joy'));
        $this->assertNull($affinity->attitude);
        $this->assertNull($affinity->relationship);
        $this->assertFalse((bool) $affinity->blacklisted);
    }
}
