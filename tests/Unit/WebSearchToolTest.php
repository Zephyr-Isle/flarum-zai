<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Zephyrisle\FlarumZaiBot\Service\Tool\WebSearchTool;

class WebSearchToolTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function enabledSettings(): SettingsRepositoryInterface
    {
        $settings = Mockery::mock(SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->with('flarum-zai-bot.jina_optimization_mode', false)->andReturn(true);

        return $settings;
    }

    public function testRejectsNonHttpProtocols(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')->never();

        $tool = new WebSearchTool($this->enabledSettings(), $client, Mockery::mock(UrlGenerator::class));

        $this->assertStringContainsString('仅支持 http/https', $tool->execute(['url' => 'file:///etc/passwd']));
        $this->assertStringContainsString('仅支持 http/https', $tool->execute(['url' => 'javascript:alert(1)']));
        $this->assertStringContainsString('仅支持 http/https', $tool->execute(['url' => 'ftp://example.com/file']));
        $this->assertStringContainsString('仅支持 http/https', $tool->execute(['url' => 'not-a-url']));
    }

    public function testAllowsHttpUrlAndFetchesContent(): void
    {
        $settings = $this->enabledSettings();
        $settings->shouldReceive('get')->with('flarum-zai-bot.jina_proxy_url', '')->andReturn('');
        $settings->shouldReceive('get')->with('flarum-zai-bot.jina_use_builtin_proxy', false)->andReturn(false);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')
            ->once()
            ->with('https://r.jinaai.cn/https://example.com', Mockery::type('array'))
            ->andReturn(new Response(200, [], json_encode(['data' => ['content' => '页面内容']])));

        $tool = new WebSearchTool($settings, $client, Mockery::mock(UrlGenerator::class));

        $result = $tool->execute(['url' => 'https://example.com']);

        $this->assertStringContainsString('页面内容', $result);
    }

    public function testAcceptsUppercaseScheme(): void
    {
        $settings = $this->enabledSettings();
        $settings->shouldReceive('get')->with('flarum-zai-bot.jina_proxy_url', '')->andReturn('');
        $settings->shouldReceive('get')->with('flarum-zai-bot.jina_use_builtin_proxy', false)->andReturn(false);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')
            ->once()
            ->with('https://r.jinaai.cn/HTTPS://example.com', Mockery::type('array'))
            ->andReturn(new Response(200, [], json_encode(['data' => ['content' => 'ok']])));

        $tool = new WebSearchTool($settings, $client, Mockery::mock(UrlGenerator::class));

        $result = $tool->execute(['url' => 'HTTPS://example.com']);

        $this->assertStringContainsString('ok', $result);
    }

    public function testRejectsHostWithWhitespace(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')->never();

        $tool = new WebSearchTool($this->enabledSettings(), $client, Mockery::mock(UrlGenerator::class));

        // 换行/制表符/空格都会被 parse_url 剥离或造成解析歧义，必须一律拒绝
        $this->assertStringContainsString('仅支持 http/https', $tool->execute(['url' => "https://exa\nmple.com"]));
        $this->assertStringContainsString('仅支持 http/https', $tool->execute(['url' => "https://exa\tmple.com"]));
        $this->assertStringContainsString('仅支持 http/https', $tool->execute(['url' => 'https://exa mple.com']));
        $this->assertStringContainsString('仅支持 http/https', $tool->execute(['url' => 'https://example.com\\@evil.com']));
    }
}

