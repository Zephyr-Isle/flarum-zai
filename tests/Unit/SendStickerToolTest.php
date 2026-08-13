<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ramon\Stickers\Models\Sticker;
use Zephyrisle\FlarumZaiBot\Service\Tool\SendStickerTool;

class SendStickerToolTest extends TestCase
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

    public function testFindsByStickerId(): void
    {
        $sticker = $this->makeSticker([]);

        $result = (new SendStickerTool())->execute(['sticker_id' => $sticker->id]);

        $this->assertStringContainsString(':smile:', $result);
    }

    public function testFindsByExactCode(): void
    {
        $this->makeSticker([]);

        $result = (new SendStickerTool())->execute(['sticker_code' => ':smile:']);

        $this->assertStringContainsString(':smile:', $result);
    }

    public function testNormalizesBareCode(): void
    {
        $this->makeSticker([]);

        $result = (new SendStickerTool())->execute(['sticker_code' => 'smile']);

        $this->assertStringContainsString(':smile:', $result);
    }

    public function testFindsByTitleQuery(): void
    {
        $this->makeSticker([]);

        $result = (new SendStickerTool())->execute(['query' => 'Smile']);

        $this->assertStringContainsString(':smile:', $result);
    }

    public function testFindsByCategoryNameQuery(): void
    {
        $this->makeSticker(['category' => 'meme', 'category_name' => 'Memes']);

        $result = (new SendStickerTool())->execute(['query' => 'Memes']);

        $this->assertStringContainsString(':smile:', $result);
    }

    public function testReturnsNotFoundMessage(): void
    {
        $this->makeSticker([]);

        $result = (new SendStickerTool())->execute(['sticker_code' => ':nope:']);

        $this->assertStringContainsString('未找到匹配的贴纸', $result);
    }

    public function testHandlesMissingTitle(): void
    {
        $this->makeSticker(['title' => null]);

        $result = (new SendStickerTool())->execute(['sticker_code' => ':smile:']);

        $this->assertStringContainsString(':smile:', $result);
    }
}
