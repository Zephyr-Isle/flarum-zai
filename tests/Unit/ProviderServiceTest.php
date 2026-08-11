<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use Flarum\Settings\SettingsRepositoryInterface;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Zephyrisle\FlarumZaiBot\Service\ProviderService;

class ProviderServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var SettingsRepositoryInterface&Mockery\MockInterface */
    protected $settings;

    protected function setUp(): void
    {
        $this->settings = Mockery::mock(SettingsRepositoryInterface::class);
    }

    protected function service(): ProviderService
    {
        return new ProviderService($this->settings);
    }

    public function testChatEndpointsReturnsEmptyWhenNoProvidersConfigured(): void
    {
        // 旧版 api_url / api_keys / model 设置已删除，未配置供应商时不应再回退
        $this->settings->shouldReceive('get')->andReturnUsing(function (string $key, mixed $default = null) {
            return match ($key) {
                'flarum-zai-bot.providers' => '',
                default => $default,
            };
        });

        $this->assertSame([], $this->service()->chatEndpoints());
    }

    public function testChatEndpointsParsesProvidersAndFlattensKeys(): void
    {
        $this->settings->shouldReceive('get')->andReturnUsing(function (string $key, mixed $default = null) {
            return match ($key) {
                'flarum-zai-bot.providers' => json_encode([
                    ['name' => 'DeepSeek', 'api_url' => 'https://api.deepseek.com/v1', 'api_keys' => 'sk-a,sk-b', 'model' => 'deepseek-chat'],
                    ['name' => 'OpenAI', 'api_url' => 'https://api.openai.com/v1', 'api_keys' => 'sk-c'],
                ]),
                default => $default,
            };
        });

        $endpoints = $this->service()->chatEndpoints();

        $this->assertCount(3, $endpoints);
        $this->assertSame('DeepSeek', $endpoints[0]['name']);
        $this->assertSame('https://api.deepseek.com/v1', $endpoints[0]['api_url']);
        $this->assertSame('sk-a', $endpoints[0]['api_key']);
        $this->assertSame('deepseek-chat', $endpoints[0]['model']);
        $this->assertSame('sk-b', $endpoints[1]['api_key']);
        // 未指定 model 的供应商回退到默认模型
        $this->assertSame('OpenAI', $endpoints[2]['name']);
        $this->assertSame('gpt-4o-mini', $endpoints[2]['model']);
    }

    public function testProvidersFiltersDisabledAndInvalidEntries(): void
    {
        $this->settings->shouldReceive('get')->with('flarum-zai-bot.providers', '')->andReturn(json_encode([
            ['name' => 'Enabled', 'api_url' => 'https://a.example/v1', 'api_keys' => 'k1'],
            ['name' => 'Disabled', 'api_url' => 'https://b.example/v1', 'api_keys' => 'k2', 'enabled' => false],
            ['name' => 'NoKeys', 'api_url' => 'https://c.example/v1', 'api_keys' => ''],
            'not-an-array',
        ]));

        $providers = $this->service()->providers();

        $this->assertCount(1, $providers);
        $this->assertSame('Enabled', $providers[0]['name']);
    }

    public function testEmbeddingEndpointsReturnsEmptyWhenNoProvidersConfigured(): void
    {
        // 旧版 embedding_api_url / embedding_api_keys / api_keys 回退已删除
        $this->settings->shouldReceive('get')->andReturnUsing(function (string $key, mixed $default = null) {
            return match ($key) {
                'flarum-zai-bot.providers' => '',
                default => $default,
            };
        });

        $this->assertSame([], $this->service()->embeddingEndpoints());
    }

    public function testEmbeddingEndpointsUseProvidersWithoutModel(): void
    {
        $this->settings->shouldReceive('get')->andReturnUsing(function (string $key, mixed $default = null) {
            return match ($key) {
                'flarum-zai-bot.providers' => json_encode([
                    // 未指定 model：embedding 端点不应携带 model（模型统一用 embedding_model 设置）
                    ['name' => 'DeepSeek', 'api_url' => 'https://api.deepseek.com/v1', 'api_keys' => 'sk-a,sk-b'],
                ]),
                default => $default,
            };
        });

        $endpoints = $this->service()->embeddingEndpoints();

        $this->assertCount(2, $endpoints);
        $this->assertSame('https://api.deepseek.com/v1', $endpoints[0]['api_url']);
        $this->assertSame('sk-a', $endpoints[0]['api_key']);
        $this->assertSame('sk-b', $endpoints[1]['api_key']);
        // 扁平化时 embedding 端点不携带 model（模型统一用 embedding_model 设置）
        $this->assertArrayNotHasKey('model', $endpoints[0]);
    }

    public function testNextStartIndexRoundRobins(): void
    {
        $this->settings->shouldReceive('get')->with('zai.last', -1)->andReturnValues([-1, 1, 2]);

        $service = $this->service();
        $this->assertSame(0, $service->nextStartIndex('zai.last', 3));
        $this->assertSame(2, $service->nextStartIndex('zai.last', 3));
        $this->assertSame(0, $service->nextStartIndex('zai.last', 3));
    }

    public function testRotateEndpoints(): void
    {
        $endpoints = [
            ['name' => 'A'],
            ['name' => 'B'],
            ['name' => 'C'],
        ];

        $this->assertSame(['A', 'B', 'C'], array_column($this->service()->rotateEndpoints($endpoints, 0), 'name'));
        $this->assertSame(['A', 'B', 'C'], array_column($this->service()->rotateEndpoints($endpoints, -1), 'name'));
        $this->assertSame(['C', 'A', 'B'], array_column($this->service()->rotateEndpoints($endpoints, 2), 'name'));
    }

    public function testSaveIndexWritesLastUsedEndpointIndex(): void
    {
        $endpoints = [
            ['name' => 'A', 'api_url' => 'https://a', 'api_key' => 'k1', 'model' => 'm1'],
            ['name' => 'B', 'api_url' => 'https://b', 'api_key' => 'k2', 'model' => 'm2'],
        ];

        $this->settings->shouldReceive('set')->with('zai.last', '1')->once();

        $this->service()->saveIndex('zai.last', $endpoints, $endpoints[1]);
    }

    public function testRemoveApiKeyFromProvidersJson(): void
    {
        $this->settings->shouldReceive('get')->with('flarum-zai-bot.providers', '')->andReturn(json_encode([
            ['name' => 'A', 'api_url' => 'https://a', 'api_keys' => 'k1,k2'],
            ['name' => 'B', 'api_url' => 'https://b', 'api_keys' => 'k3'],
        ]));

        $this->settings->shouldReceive('set')->once()->with('flarum-zai-bot.providers', Mockery::on(function (string $json) {
            $decoded = json_decode($json, true);
            return $decoded[0]['api_keys'] === 'k2' && $decoded[1]['api_keys'] === 'k3';
        }));

        $this->service()->removeApiKey('k1');
    }

    public function testRemoveApiKeyDropsProviderWithNoKeysLeft(): void
    {
        $this->settings->shouldReceive('get')->with('flarum-zai-bot.providers', '')->andReturn(json_encode([
            ['name' => 'A', 'api_url' => 'https://a', 'api_keys' => 'k1'],
            ['name' => 'B', 'api_url' => 'https://b', 'api_keys' => 'k2,k3'],
        ]));

        $this->settings->shouldReceive('set')->once()->with('flarum-zai-bot.providers', Mockery::on(function (string $json) {
            $decoded = json_decode($json, true);
            // 供应商 A 移除唯一密钥后已无密钥，应被整体移除；B 不受影响
            return count($decoded) === 1 && $decoded[0]['name'] === 'B' && $decoded[0]['api_keys'] === 'k2,k3';
        }));

        $this->service()->removeApiKey('k1');
    }

    public function testRemoveApiKeyDoesNothingWithoutProviders(): void
    {
        // 旧版设置已删除：providers 为空时移除密钥应为空操作
        $this->settings->shouldReceive('get')->with('flarum-zai-bot.providers', '')->andReturn('');
        $this->settings->shouldReceive('set')->never();

        $this->service()->removeApiKey('k1');
    }
}
