<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Zephyrisle\FlarumZaiBot\Service\Media\LinkParsingService;

class LinkParsingServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function service(array $values = [], ?Client $client = null): LinkParsingService
    {
        $settings = Mockery::mock(SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->andReturnUsing(function (string $key, mixed $default = null) use ($values) {
            return array_key_exists($key, $values) ? $values[$key] : $default;
        });

        return new LinkParsingService(
            $settings,
            $client ?? Mockery::mock(Client::class),
            new CacheRepository(new ArrayStore())
        );
    }

    public function testExtractsTitleAndSummary(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')
            ->once()
            ->with('https://example.com/page', Mockery::on(fn ($opts) => is_array($opts)))
            ->andReturn(new Response(200, ['Content-Type' => 'text/html'], '<html><head><title>Test Page</title><meta name="description" content="A nice summary of the page."></head><body><p>ignored</p></body></html>'));

        $results = $this->service([], $client)->parse('看看这个 <a href="https://example.com/page">链接</a>');

        $this->assertCount(1, $results);
        $this->assertSame('https://example.com/page', $results[0]['url']);
        $this->assertSame('Test Page', $results[0]['title']);
        $this->assertSame('A nice summary of the page.', $results[0]['summary']);
    }

    public function testFallsBackToVisibleTextWithoutMetaDescription(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')
            ->once()
            ->andReturn(new Response(200, ['Content-Type' => 'text/html'], '<html><head><title>T</title></head><body><p>Hello world from the page body text.</p></body></html>'));

        $results = $this->service([], $client)->parse('https://example.com/x');

        $this->assertCount(1, $results);
        $this->assertStringContainsString('Hello world from the page body text', $results[0]['summary']);
    }

    public function testCachesResultAndDoesNotRefetch(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')
            ->once()
            ->andReturn(new Response(200, ['Content-Type' => 'text/html'], '<html><head><title>Cached Title</title></head><body>body</body></html>'));

        $service = $this->service([], $client);

        $first = $service->parse('https://example.com/cached');
        $second = $service->parse('https://example.com/cached');

        $this->assertSame($first, $second);
    }

    public function testBlocksPrivateIpAndLocalhost(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')->never();

        $service = $this->service([], $client);

        $this->assertSame([], $service->parse('http://127.0.0.1/admin http://192.168.1.5/router http://localhost/status'));
    }

    public function testBlocksBlacklistedDomain(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')->never();

        $service = $this->service([
            'flarum-zai-bot.media_link_blacklist' => "evil.example\n# comment\nspam.net",
        ], $client);

        $this->assertSame([], $service->parse('https://evil.example/x https://sub.spam.net/page'));
    }

    public function testSkipsNonHtmlContentType(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')
            ->once()
            ->andReturn(new Response(200, ['Content-Type' => 'application/pdf'], 'PDF_BYTES'));

        $this->assertSame([], $this->service([], $client)->parse('https://example.com/doc.pdf'));
    }

    public function testRespectsMaxBytes(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')
            ->once()
            ->andReturn(new Response(200, ['Content-Type' => 'text/html'], '<html><head><title>Test Page</title></head><body><p>' . str_repeat('x', 2000) . '</p></body></html>'));

        // 服务端钳制最低 1024 字节；正文远超上限 → 摘要截断
        $results = $this->service([
            'flarum-zai-bot.media_link_max_bytes' => 1024,
        ], $client)->parse('https://example.com/big');

        $this->assertCount(1, $results);
        $this->assertSame('Test Page', $results[0]['title']);
        $this->assertStringEndsWith('…', $results[0]['summary']);
    }

    public function testLimitsNumberOfParsedLinks(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')->twice()->andReturn(
            new Response(200, ['Content-Type' => 'text/html'], '<html><title>A</title><body>x</body></html>'),
            new Response(200, ['Content-Type' => 'text/html'], '<html><title>B</title><body>x</body></html>')
        );

        $results = $this->service([
            'flarum-zai-bot.media_link_max_links' => 2,
        ], $client)->parse('https://example.com/a https://example.com/b https://example.com/c');

        $this->assertCount(2, $results);
    }
}
