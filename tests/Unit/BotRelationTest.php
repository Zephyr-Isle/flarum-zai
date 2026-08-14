<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zephyrisle\FlarumZaiBot\Model\BotRelation;

class BotRelationTest extends TestCase
{
    protected function tearDown(): void
    {
        BotRelation::query()->delete();
    }

    public function testGetOrCreatePersistsAndReuses(): void
    {
        $relation = BotRelation::getOrCreate(1);

        $this->assertSame(1, (int) $relation->user_id);
        $this->assertNotNull(BotRelation::where('user_id', 1)->first());

        $again = BotRelation::getOrCreate(1);
        $this->assertSame($relation->id, $again->id);
    }

    public function testUpdateFieldsOverridesAndNormalizes(): void
    {
        $relation = BotRelation::getOrCreate(2);

        $relation->updateFields([
            'identity' => '论坛管理员，精通 PHP',
            'aliases' => ['小雪', '雪姐', '雪姐', '  '],
            'group_profile' => '常驻技术板块',
            'boundaries' => ['不喜欢被评价身材'],
        ]);

        $this->assertSame('论坛管理员，精通 PHP', $relation->identity);
        $this->assertSame(['小雪', '雪姐'], $relation->aliases);
        $this->assertSame('常驻技术板块', $relation->group_profile);
        $this->assertSame(['不喜欢被评价身材'], $relation->boundaries);
    }

    public function testEmptyIdentityBecomesNull(): void
    {
        $relation = BotRelation::getOrCreate(3);
        $relation->updateFields(['identity' => '原来的身份']);
        $relation->updateFields(['identity' => '   ']);

        $this->assertNull($relation->identity);
    }

    public function testAddBoundaryDeduplicates(): void
    {
        $relation = BotRelation::getOrCreate(4);

        $relation->addBoundary('不要聊工作');
        $relation->addBoundary('不要聊工作');
        $relation->addBoundary('  ');

        $this->assertSame(['不要聊工作'], $relation->boundaries);
    }

    public function testPendingObservationConfirmLandInIdentity(): void
    {
        $relation = BotRelation::getOrCreate(5);

        $relation->addPendingObservation('可能是资深后端工程师', '对方提到写过 8 年 PHP', 'identity');
        $this->assertCount(1, $relation->pending_observations);

        $ok = $relation->confirmObservation(0);

        $this->assertTrue($ok);
        $this->assertSame('可能是资深后端工程师', $relation->identity);
        $this->assertSame([], $relation->pending_observations ?? []);
    }

    public function testPendingObservationConfirmAppendsToExistingText(): void
    {
        $relation = BotRelation::getOrCreate(6);
        $relation->updateFields(['identity' => '论坛管理员']);

        $relation->addPendingObservation('精通 PostgreSQL', '', 'identity');
        $relation->confirmObservation(0);

        $this->assertSame('论坛管理员；精通 PostgreSQL', $relation->identity);
    }

    public function testPendingObservationConfirmFieldRouting(): void
    {
        $relation = BotRelation::getOrCreate(7);

        $relation->addPendingObservation('常去资源区', '', 'group_profile');
        $relation->confirmObservation(0);

        $this->assertNull($relation->identity);
        $this->assertSame('常去资源区', $relation->group_profile);
    }

    public function testPendingObservationRejectRemovesWithoutLanding(): void
    {
        $relation = BotRelation::getOrCreate(8);

        $relation->addPendingObservation('可疑的临时观察', '', 'identity');
        $ok = $relation->rejectObservation(0);

        $this->assertTrue($ok);
        $this->assertNull($relation->identity);
        $this->assertSame([], $relation->pending_observations ?? []);
    }

    public function testConfirmMissingIndexReturnsFalse(): void
    {
        $relation = BotRelation::getOrCreate(9);

        $this->assertFalse($relation->confirmObservation(5));
        $this->assertFalse($relation->rejectObservation(5));
    }

    public function testPendingObservationCapAtFifty(): void
    {
        $relation = BotRelation::getOrCreate(10);

        for ($i = 0; $i < 55; $i++) {
            $relation->addPendingObservation("观察 {$i}", '', 'identity');
        }

        $this->assertCount(50, $relation->pending_observations);
        // 最旧的被移出，最新保留
        $this->assertStringContainsString('观察 5', $relation->pending_observations[0]['observation']);
        $this->assertStringContainsString('观察 54', $relation->pending_observations[49]['observation']);
    }
}
