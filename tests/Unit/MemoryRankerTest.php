<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zephyrisle\FlarumZaiBot\Service\Memory\MemoryRanker;

class MemoryRankerTest extends TestCase
{
    protected function ranker(): MemoryRanker
    {
        return new MemoryRanker();
    }

    public function testTokenizeSplitsLatinWords(): void
    {
        $this->assertSame(['hello', 'world'], $this->ranker()->tokenize('Hello World'));
    }

    public function testTokenizeGeneratesCjkBigrams(): void
    {
        $tokens = $this->ranker()->tokenize('用户喜欢喝咖啡');

        // 中文按单字二元组切分
        $this->assertContains('用户', $tokens);
        $this->assertContains('户喜', $tokens);
        $this->assertContains('喜欢', $tokens);
        $this->assertContains('咖啡', $tokens);
    }

    public function testTokenizeHandlesMixedAndSingleChar(): void
    {
        $tokens = $this->ranker()->tokenize('AI 好');

        $this->assertContains('ai', $tokens);
        $this->assertContains('好', $tokens);
    }

    public function testTokenizeEmpty(): void
    {
        $this->assertSame([], $this->ranker()->tokenize(''));
        $this->assertSame([], $this->ranker()->tokenize('   '));
    }

    public function testBm25ScoresRelevantDocHigher(): void
    {
        $docs = [
            1 => ['content' => '用户喜欢喝美式咖啡，不喜欢加糖'],
            2 => ['content' => '用户上周末去了郊外爬山，拍了很多照片'],
            3 => ['content' => '用户养了一只叫煤球的黑色猫咪'],
        ];
        $tokens = $this->ranker()->tokenize('用户喜欢什么咖啡');

        $scores = $this->ranker()->bm25Scores($tokens, $docs);

        // 包含“用户/喜欢/咖啡”的记忆得分最高
        $this->assertGreaterThan($scores[2] ?? 0, $scores[1] ?? 0);
        $this->assertGreaterThan($scores[3] ?? 0, $scores[1] ?? 0);
    }

    public function testBm25EmptyInputs(): void
    {
        $this->assertSame([], $this->ranker()->bm25Scores([], [1 => ['content' => 'x']]));
        $this->assertSame([], $this->ranker()->bm25Scores(['a'], []));
    }

    public function testFuseCombinesBothPaths(): void
    {
        $vector = [1 => 0.9, 2 => 0.5];
        $keyword = [1 => 0.2, 2 => 1.0];

        $fused = $this->ranker()->fuse($vector, $keyword, 0.5);

        // 文档1：0.5*0.9 + 0.5*0.2 = 0.55；文档2：0.5*0.5 + 0.5*1.0 = 0.75
        $this->assertGreaterThan($fused[1], $fused[2]);
        $this->assertEqualsWithDelta(0.55, $fused[1], 0.001);
    }

    public function testFuseWithKeywordOnlyPath(): void
    {
        $vector = [1 => 0.6, 2 => 0.4];
        $keyword = [2 => 1.0];

        // 文档1 无关键词命中：0.6*0.6 = 0.36；文档2：0.6*0.4 + 0.4*1.0 = 0.64
        $fused = $this->ranker()->fuse($vector, $keyword, 0.6);

        $this->assertGreaterThan($fused[1], $fused[2]);
    }

    public function testFuseEmptyVector(): void
    {
        $this->assertSame([], $this->ranker()->fuse([], [1 => 1.0]));
    }

    public function testFuseClampsWeight(): void
    {
        $fused = $this->ranker()->fuse([1 => 1.0], [1 => 1.0], 5.0);
        // 权重钳制到 1.0：纯向量分数
        $this->assertEqualsWithDelta(1.0, $fused[1], 0.001);
    }

    public function testApplyDynamicsAddsImportanceBoost(): void
    {
        $low = $this->ranker()->applyDynamics(0.5, 0, null, 30);
        $high = $this->ranker()->applyDynamics(0.5, 5, null, 30);

        $this->assertGreaterThan($low, $high);
    }

    public function testApplyDynamicsAppliesDecay(): void
    {
        $fresh = $this->ranker()->applyDynamics(0.5, 0, 1, 30);
        $stale = $this->ranker()->applyDynamics(0.5, 0, 90, 30);

        $this->assertLessThan($fresh, $stale);
    }

    public function testApplyDynamicsNeverNegative(): void
    {
        $score = $this->ranker()->applyDynamics(0.0, -5, 9999, 1);

        $this->assertGreaterThanOrEqual(0.0, $score);
    }

    public function testIsExpired(): void
    {
        $ranker = $this->ranker();

        $this->assertFalse($ranker->isExpired(null));
        $this->assertFalse($ranker->isExpired(''));
        $this->assertTrue($ranker->isExpired(date('Y-m-d H:i:s', time() - 3600)));
        $this->assertFalse($ranker->isExpired(date('Y-m-d H:i:s', time() + 3600)));
        $this->assertTrue($ranker->isExpired(time() - 10));
        $this->assertFalse($ranker->isExpired(time() + 10));
    }
}
