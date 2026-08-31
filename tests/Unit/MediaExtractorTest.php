<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zephyrisle\FlarumZaiBot\Service\MediaExtractor;

class MediaExtractorTest extends TestCase
{
    public function testExtractsImageUrlsFromHtml(): void
    {
        $html = '<p>看看这张图：</p><img src="https://example.com/a.png" alt="a"><img src="/relative.png"><p>还有</p><img src="https://example.com/b.jpg">';

        $this->assertSame(
            ['https://example.com/a.png', 'https://example.com/b.jpg'],
            MediaExtractor::fromHtml($html)
        );
    }

    public function testSkipsEmojiCdnImages(): void
    {
        $html = '<p>😄</p><img src="https://cdn.jsdelivr.net/gh/twitter/twemoji@14.0.2/assets/72x72/1f604.png"><img src="https://example.com/photo.jpg">';

        $this->assertSame(['https://example.com/photo.jpg'], MediaExtractor::fromHtml($html));
    }

    public function testAcceptsDataUris(): void
    {
        $html = '<img src="data:image/png;base64,iVBORw0KGgo=">';

        $this->assertSame(['data:image/png;base64,iVBORw0KGgo='], MediaExtractor::fromHtml($html));
    }

    public function testDeduplicatesAndRespectsMax(): void
    {
        $html = '<img src="https://example.com/x.png"><img src="https://example.com/x.png"><img src="https://example.com/y.png"><img src="https://example.com/z.png">';

        $this->assertSame(
            ['https://example.com/x.png', 'https://example.com/y.png'],
            MediaExtractor::fromHtml($html, 2)
        );
    }

    public function testEmptyHtmlReturnsEmpty(): void
    {
        $this->assertSame([], MediaExtractor::fromHtml(''));
        $this->assertSame([], MediaExtractor::fromHtml('<p>没有图片</p>'));
    }

    public function testDecodesHtmlEntitiesInSrc(): void
    {
        $html = '<img src="https://example.com/a&amp;b.png">';

        $this->assertSame(['https://example.com/a&b.png'], MediaExtractor::fromHtml($html));
    }

    public function testRewritesFofUploadUrlToVisionProxy(): void
    {
        $html = '<img src="https://forum.example/api/fof/download/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee">';

        $this->assertSame(
            ['https://forum.example/api/zai-bot/vision-media/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'],
            MediaExtractor::fromHtml($html, 4, 'https://forum.example')
        );
    }

    public function testRewritesClassicFofUploadUrlToVisionProxy(): void
    {
        $html = '<img src="https://forum.example/assets/files/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee/photo.png">';

        $this->assertSame(
            ['https://forum.example/api/zai-bot/vision-media/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'],
            MediaExtractor::fromHtml($html, 4, 'https://forum.example')
        );
    }

    public function testRewritesRelativeFofUploadUrl(): void
    {
        $html = '<img src="/api/fof/download/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee">';

        $this->assertSame(
            ['https://forum.example/api/zai-bot/vision-media/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'],
            MediaExtractor::fromHtml($html, 4, 'https://forum.example/')
        );
    }

    public function testKeepsOrdinaryImageUrlsUntouched(): void
    {
        $html = '<img src="https://imgur.com/abc.png"><img src="data:image/png;base64,iVBORw0KGgo=">';

        $this->assertSame(
            ['https://imgur.com/abc.png', 'data:image/png;base64,iVBORw0KGgo='],
            MediaExtractor::fromHtml($html)
        );
    }

    public function testDropsFofUploadUrlWhenNoForumUrlResolvable(): void
    {
        $html = '<img src="/api/fof/download/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee"><img src="https://imgur.com/abc.png">';

        $this->assertSame(
            ['https://imgur.com/abc.png'],
            MediaExtractor::fromHtml($html)
        );
    }

    public function testUploadUuidsExtractsAndDeduplicates(): void
    {
        $html = '<img src="https://forum.example/api/fof/download/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee">'
            . '<img src="https://forum.example/api/fof/download/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee">'
            . '<img src="https://forum.example/assets/files/bbbbbbbb-cccc-dddd-eeee-ffffffffffff/x.png">';

        $this->assertSame(
            ['aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff'],
            MediaExtractor::uploadUuids($html)
        );
    }

    public function testClassifyDetectsGifEmojiAndSticker(): void
    {
        $this->assertSame('gif', MediaExtractor::classify('https://example.com/anim.gif'));
        $this->assertSame('gif', MediaExtractor::classify('https://example.com/file?content-type=gif'));
        $this->assertSame('emoji', MediaExtractor::classify('https://cdn.example.com/emoji/1f600.png'));
        $this->assertSame('emoji', MediaExtractor::classify('https://example.com/twemoji/1f600.png'));
        $this->assertSame('sticker', MediaExtractor::classify('https://example.com/sticker/pack/1.png'));
        $this->assertSame('image', MediaExtractor::classify('https://example.com/photo.jpg'));
        $this->assertSame('image', MediaExtractor::classify('data:image/png;base64,AAA='));
    }

    // ===== 视频提取测试 =====

    public function testExtractsVideoUrlsFromVideoTag(): void
    {
        $html = '<p>看这个视频</p><video src="https://example.com/clip.mp4" controls></video>';

        $this->assertSame(
            ['https://example.com/clip.mp4'],
            MediaExtractor::videosFromHtml($html)
        );
    }

    public function testExtractsVideoFromSourceTag(): void
    {
        $html = '<video controls><source src="https://example.com/clip.mp4" type="video/mp4"></video>';

        $this->assertSame(
            ['https://example.com/clip.mp4'],
            MediaExtractor::videosFromHtml($html)
        );
    }

    public function testExtractsDataUriVideo(): void
    {
        $html = '<video src="data:video/mp4;base64,AAAAIGZ0eXBpc29"></video>';

        $this->assertCount(1, MediaExtractor::videosFromHtml($html));
    }

    public function testVideosFromHtmlRespectsMax(): void
    {
        $html = '<video src="https://example.com/a.mp4"></video><video src="https://example.com/b.mp4"></video><video src="https://example.com/c.mp4"></video>';

        $this->assertCount(2, MediaExtractor::videosFromHtml($html, 2));
    }

    public function testVideosFromHtmlEmptyReturnsEmpty(): void
    {
        $this->assertSame([], MediaExtractor::videosFromHtml(''));
        $this->assertSame([], MediaExtractor::videosFromHtml('<p>没有视频</p>'));
    }

    public function testRewritesFofUploadVideoToVisionProxy(): void
    {
        $html = '<video src="https://forum.example/api/fof/download/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee"></video>';

        $this->assertSame(
            ['https://forum.example/api/zai-bot/vision-media/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'],
            MediaExtractor::videosFromHtml($html, 2, 'https://forum.example')
        );
    }

    // ===== 音频提取测试 =====

    public function testExtractsAudioUrlsFromAudioTag(): void
    {
        $html = '<p>听听这个</p><audio src="https://example.com/voice.mp3" controls></audio>';

        $this->assertSame(
            ['https://example.com/voice.mp3'],
            MediaExtractor::audiosFromHtml($html)
        );
    }

    public function testExtractsAudioFromSourceTag(): void
    {
        $html = '<audio controls><source src="https://example.com/voice.wav" type="audio/wav"></audio>';

        $this->assertSame(
            ['https://example.com/voice.wav'],
            MediaExtractor::audiosFromHtml($html)
        );
    }

    public function testExtractsDataUriAudio(): void
    {
        $html = '<audio src="data:audio/wav;base64,UklGRiQAA"></audio>';

        $this->assertCount(1, MediaExtractor::audiosFromHtml($html));
    }

    public function testAudiosFromHtmlRespectsMax(): void
    {
        $html = '<audio src="https://example.com/a.mp3"></audio><audio src="https://example.com/b.mp3"></audio><audio src="https://example.com/c.mp3"></audio>';

        $this->assertCount(2, MediaExtractor::audiosFromHtml($html, 2));
    }

    public function testAudiosFromHtmlEmptyReturnsEmpty(): void
    {
        $this->assertSame([], MediaExtractor::audiosFromHtml(''));
        $this->assertSame([], MediaExtractor::audiosFromHtml('<p>没有音频</p>'));
    }

    public function testRewritesFofUploadAudioToVisionProxy(): void
    {
        $html = '<audio src="https://forum.example/api/fof/download/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee"></audio>';

        $this->assertSame(
            ['https://forum.example/api/zai-bot/vision-media/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'],
            MediaExtractor::audiosFromHtml($html, 2, 'https://forum.example')
        );
    }

    // ===== classifyMedia 测试 =====

    public function testClassifyMediaDetectsTypes(): void
    {
        $this->assertSame('image', MediaExtractor::classifyMedia('https://example.com/photo.jpg'));
        $this->assertSame('image', MediaExtractor::classifyMedia('data:image/png;base64,AAA='));
        $this->assertSame('video', MediaExtractor::classifyMedia('https://example.com/clip.mp4'));
        $this->assertSame('video', MediaExtractor::classifyMedia('https://example.com/movie.mov'));
        $this->assertSame('video', MediaExtractor::classifyMedia('data:video/mp4;base64,AAA='));
        $this->assertSame('audio', MediaExtractor::classifyMedia('https://example.com/voice.mp3'));
        $this->assertSame('audio', MediaExtractor::classifyMedia('https://example.com/song.wav'));
        $this->assertSame('audio', MediaExtractor::classifyMedia('data:audio/wav;base64,AAA='));
    }
}
