<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zephyrisle\FlarumZaiBot\Service\ImageExtractor;

class ImageExtractorTest extends TestCase
{
    public function testExtractsImageUrlsFromHtml(): void
    {
        $html = '<p>看看这张图：</p><img src="https://example.com/a.png" alt="a"><img src="/relative.png"><p>还有</p><img src="https://example.com/b.jpg">';

        $this->assertSame(
            ['https://example.com/a.png', 'https://example.com/b.jpg'],
            ImageExtractor::fromHtml($html)
        );
    }

    public function testSkipsEmojiCdnImages(): void
    {
        $html = '<p>😄</p><img src="https://cdn.jsdelivr.net/gh/twitter/twemoji@14.0.2/assets/72x72/1f604.png"><img src="https://example.com/photo.jpg">';

        $this->assertSame(['https://example.com/photo.jpg'], ImageExtractor::fromHtml($html));
    }

    public function testAcceptsDataUris(): void
    {
        $html = '<img src="data:image/png;base64,iVBORw0KGgo=">';

        $this->assertSame(['data:image/png;base64,iVBORw0KGgo='], ImageExtractor::fromHtml($html));
    }

    public function testDeduplicatesAndRespectsMax(): void
    {
        $html = '<img src="https://example.com/x.png"><img src="https://example.com/x.png"><img src="https://example.com/y.png"><img src="https://example.com/z.png">';

        $this->assertSame(
            ['https://example.com/x.png', 'https://example.com/y.png'],
            ImageExtractor::fromHtml($html, 2)
        );
    }

    public function testEmptyHtmlReturnsEmpty(): void
    {
        $this->assertSame([], ImageExtractor::fromHtml(''));
        $this->assertSame([], ImageExtractor::fromHtml('<p>没有图片</p>'));
    }

    public function testDecodesHtmlEntitiesInSrc(): void
    {
        $html = '<img src="https://example.com/a&amp;b.png">';

        $this->assertSame(['https://example.com/a&b.png'], ImageExtractor::fromHtml($html));
    }

    public function testRewritesFofUploadUrlToVisionProxy(): void
    {
        // fof/upload 1.x：下载 URL 形如 {forum}/api/fof/download/{uuid}
        $html = '<img src="https://forum.example/api/fof/download/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee">';

        $this->assertSame(
            ['https://forum.example/api/zai-bot/vision-image/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'],
            ImageExtractor::fromHtml($html, 4, 'https://forum.example')
        );
    }

    public function testRewritesClassicFofUploadUrlToVisionProxy(): void
    {
        // fof/upload 0.x：URL 形如 {forum}/assets/files/{uuid}/{name}
        $html = '<img src="https://forum.example/assets/files/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee/photo.png">';

        $this->assertSame(
            ['https://forum.example/api/zai-bot/vision-image/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'],
            ImageExtractor::fromHtml($html, 4, 'https://forum.example')
        );
    }

    public function testRewritesRelativeFofUploadUrl(): void
    {
        $html = '<img src="/api/fof/download/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee">';

        $this->assertSame(
            ['https://forum.example/api/zai-bot/vision-image/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'],
            ImageExtractor::fromHtml($html, 4, 'https://forum.example/')
        );
    }

    public function testKeepsOrdinaryImageUrlsUntouched(): void
    {
        $html = '<img src="https://imgur.com/abc.png"><img src="data:image/png;base64,iVBORw0KGgo=">';

        $this->assertSame(
            ['https://imgur.com/abc.png', 'data:image/png;base64,iVBORw0KGgo='],
            ImageExtractor::fromHtml($html)
        );
    }

    public function testDropsFofUploadUrlWhenNoForumUrlResolvable(): void
    {
        // 无法解析论坛根地址时，不能生成有效的代理 URL，跳过该图片
        // （避免把 403 的死链接发给模型导致整个识图请求失败）
        $html = '<img src="/api/fof/download/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee"><img src="https://imgur.com/abc.png">';

        $this->assertSame(
            ['https://imgur.com/abc.png'],
            ImageExtractor::fromHtml($html)
        );
    }

    public function testUploadUuidsExtractsAndDeduplicates(): void
    {
        $html = '<img src="https://forum.example/api/fof/download/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee">'
            . '<img src="https://forum.example/api/fof/download/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee">'
            . '<img src="https://forum.example/assets/files/bbbbbbbb-cccc-dddd-eeee-ffffffffffff/x.png">';

        $this->assertSame(
            ['aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff'],
            ImageExtractor::uploadUuids($html)
        );
    }

    public function testClassifyDetectsGifEmojiAndSticker(): void
    {
        $this->assertSame('gif', ImageExtractor::classify('https://example.com/anim.gif'));
        $this->assertSame('gif', ImageExtractor::classify('https://example.com/file?content-type=gif'));
        $this->assertSame('emoji', ImageExtractor::classify('https://cdn.example.com/emoji/1f600.png'));
        $this->assertSame('emoji', ImageExtractor::classify('https://example.com/twemoji/1f600.png'));
        $this->assertSame('sticker', ImageExtractor::classify('https://example.com/sticker/pack/1.png'));
        $this->assertSame('image', ImageExtractor::classify('https://example.com/photo.jpg'));
        $this->assertSame('image', ImageExtractor::classify('data:image/png;base64,AAA='));
    }
}
