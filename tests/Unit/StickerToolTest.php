<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ramon\Stickers\Models\Sticker;
use Zephyrisle\FlarumZaiBot\Service\Tool\StickerTool;

class StickerToolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sticker::query()->delete();
    }

    protected function makeSticker(array $attrs): Sticker
    {
        return Sticker::create(array_merge([
            'title' => 'Smile',
            'text_to_replace' => ':smile:',
            'path' => '/assets/stickers/smile.png',
        ], $attrs));
    }

    public function testReturnsEmptyMessageWhenNoStickers(): void
    {
        $this->assertSame('暂无可用的贴纸。', (new StickerTool())->execute([]));
    }

    public function testListsStickersGroupedByCategory(): void
    {
        $this->makeSticker(['title' => 'Smile', 'text_to_replace' => ':smile:', 'category' => 'meme', 'category_name' => 'Memes']);
        $this->makeSticker(['title' => 'Cry', 'text_to_replace' => ':cry:', 'category' => 'meme', 'category_name' => 'Memes']);

        $result = (new StickerTool())->execute([]);

        $this->assertStringContainsString('【Memes】', $result);
        $this->assertStringContainsString('Smile：:smile:', $result);
        $this->assertStringContainsString('Cry：:cry:', $result);
        $this->assertStringContainsString('/assets/stickers/smile.png', $result);
    }

    public function testFiltersByCategoryDisplayName(): void
    {
        $this->makeSticker(['title' => 'A', 'text_to_replace' => ':a:', 'category' => 'meme', 'category_name' => 'Memes']);
        $this->makeSticker(['title' => 'B', 'text_to_replace' => ':b:', 'category' => 'anim', 'category_name' => 'Animations']);

        $result = (new StickerTool())->execute(['category' => 'Memes']);

        $this->assertStringContainsString(':a:', $result);
        $this->assertStringNotContainsString(':b:', $result);
    }

    public function testFiltersByCategoryCode(): void
    {
        $this->makeSticker(['title' => 'A', 'text_to_replace' => ':a:', 'category' => 'meme', 'category_name' => 'Memes']);
        $this->makeSticker(['title' => 'B', 'text_to_replace' => ':b:', 'category' => 'anim', 'category_name' => 'Animations']);

        $result = (new StickerTool())->execute(['category' => 'meme']);

        $this->assertStringContainsString(':a:', $result);
        $this->assertStringNotContainsString(':b:', $result);
    }

    public function testSearchesByCode(): void
    {
        $this->makeSticker(['title' => 'Smile', 'text_to_replace' => ':smile:']);
        $this->makeSticker(['title' => 'Cry', 'text_to_replace' => ':cry:']);

        $result = (new StickerTool())->execute(['query' => ':cry:']);

        $this->assertStringContainsString(':cry:', $result);
        $this->assertStringNotContainsString(':smile:', $result);
    }

    public function testReturnsNotFoundMessageForNoMatches(): void
    {
        $this->makeSticker(['title' => 'Smile', 'text_to_replace' => ':smile:']);

        $result = (new StickerTool())->execute(['query' => 'nope']);

        $this->assertStringContainsString('未找到', $result);
    }

    public function testRespectsLimitAndReportsMoreAvailable(): void
    {
        foreach (['one', 'two', 'three'] as $i => $name) {
            $this->makeSticker(['title' => $name, 'text_to_replace' => ":{$name}:", 'category' => 'meme', 'category_name' => 'Memes']);
        }

        $result = (new StickerTool())->execute(['limit' => 2]);

        $this->assertStringContainsString('共3个，仅显示前2个', $result);
        $this->assertStringContainsString('one：:one:', $result);
        $this->assertStringNotContainsString('three', $result);
    }

    public function testHandlesMissingTitleAndCode(): void
    {
        $this->makeSticker(['title' => null, 'text_to_replace' => null, 'path' => '/assets/stickers/none.png']);

        $result = (new StickerTool())->execute([]);

        $this->assertStringContainsString('（无标题）', $result);
        $this->assertStringContainsString('（无代码）', $result);
    }
}
