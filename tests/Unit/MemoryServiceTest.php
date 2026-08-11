<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Zephyrisle\FlarumZaiBot\Service\MemoryService;
use Zephyrisle\FlarumZaiBot\Service\ProviderService;

class MemoryServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var SettingsRepositoryInterface&Mockery\MockInterface */
    protected $settings;

    /** @var Client&Mockery\MockInterface */
    protected $client;

    protected function setUp(): void
    {
        $this->settings = Mockery::mock(SettingsRepositoryInterface::class);
        $this->client = Mockery::mock(Client::class);
    }

    protected function service(): MemoryService
    {
        return new MemoryService($this->settings, $this->client, new ProviderService($this->settings));
    }

    /**
     * 默认 embedding 相关设置：通过供应商配置提供 embedding 端点。
     * ProviderService 会读取这些设置来构建端点列表。
     */
    protected function stubEmbeddingSettings(string $apiKeys = 'k1,k2'): void
    {
        $this->settings->shouldReceive('get')->andReturnUsing(function (string $key, mixed $default = null) use ($apiKeys) {
            return match ($key) {
                'flarum-zai-bot.providers' => json_encode([
                    ['name' => 'Default', 'api_url' => 'https://api.openai.com/v1', 'api_keys' => $apiKeys],
                ]),
                'flarum-zai-bot.embedding_model' => 'text-embedding-3-small',
                'flarum-zai-bot.last_embedding_key_index' => -1,
                default => $default,
            };
        });

        $this->settings->shouldReceive('set')->byDefault()->andReturnNull();
    }

    public function testIsAvailableReturnsFalseWithoutPgvectorHost(): void
    {
        $this->settings->shouldReceive('get')->with('flarum-zai-bot.pgvector_host')->andReturn(null);

        $this->assertFalse($this->service()->isAvailable());
    }

    public function testIsAvailableReturnsFalseWhenDatabaseMissing(): void
    {
        $this->settings->shouldReceive('get')->with('flarum-zai-bot.pgvector_host')->andReturn('127.0.0.1');
        $this->settings->shouldReceive('get')->with('flarum-zai-bot.pgvector_port', '5432')->andReturn('5432');
        $this->settings->shouldReceive('get')->with('flarum-zai-bot.pgvector_db')->andReturn(null);
        $this->settings->shouldReceive('get')->with('flarum-zai-bot.pgvector_user')->andReturn(null);
        $this->settings->shouldReceive('get')->with('flarum-zai-bot.pgvector_password')->andReturn(null);

        $this->assertFalse($this->service()->isAvailable());
    }

    public function testGenerateEmbeddingReturnsEmbeddingVector(): void
    {
        $this->stubEmbeddingSettings();

        $this->client->shouldReceive('post')
            ->once()
            ->andReturn(new Response(200, [], json_encode([
                'data' => [['embedding' => [0.1, 0.2, 0.3]]],
            ])));

        $embedding = $this->service()->generateEmbedding('测试文本');

        $this->assertSame([0.1, 0.2, 0.3], $embedding);
    }

    public function testGenerateEmbeddingSkipsDepletedKeyAndRemovesIt(): void
    {
        $this->stubEmbeddingSettings();

        // First key: 402 with a quota message → treated as depleted
        $this->client->shouldReceive('post')
            ->once()
            ->andThrow(new ClientException(
                'quota exceeded',
                new Request('POST', 'https://api.openai.com/v1/embeddings'),
                new Response(402, [], json_encode(['error' => ['message' => 'insufficient_quota']]))
            ));

        // Second key: success
        $this->client->shouldReceive('post')
            ->once()
            ->andReturn(new Response(200, [], json_encode([
                'data' => [['embedding' => [9.9]]],
            ])));

        // Depleted key 'k1' is removed from the provider's keys via ProviderService
        $this->settings->shouldReceive('set')
            ->with('flarum-zai-bot.providers', Mockery::on(function (string $json) {
                $decoded = json_decode($json, true);
                return ($decoded[0]['api_keys'] ?? '') === 'k2';
            }))
            ->once();

        $embedding = $this->service()->generateEmbedding('hello');

        $this->assertSame([9.9], $embedding);
    }

    public function testGenerateEmbeddingContinuesOnEmptyResult(): void
    {
        $this->stubEmbeddingSettings();

        // First key responds 200 but without usable embedding data
        $this->client->shouldReceive('post')
            ->once()
            ->andReturn(new Response(200, [], json_encode(['data' => []])));

        // Second key returns a proper embedding (1.5 keeps its float type through json_encode/decode)
        $this->client->shouldReceive('post')
            ->once()
            ->andReturn(new Response(200, [], json_encode([
                'data' => [['embedding' => [1.5]]],
            ])));

        $embedding = $this->service()->generateEmbedding('hello');

        $this->assertSame([1.5], $embedding);
    }

    public function testGenerateEmbeddingReturnsNullWithNoKeys(): void
    {
        // 供应商未配置密钥 → 无可用端点
        $this->stubEmbeddingSettings('');

        $this->assertNull($this->service()->generateEmbedding('hello'));
    }

    public function testStoreMemoryReturnsFalseWhenUnavailable(): void
    {
        $this->settings->shouldReceive('get')->with('flarum-zai-bot.pgvector_host')->andReturn(null);

        $this->assertFalse($this->service()->storeMemory(1, '记忆内容', [0.1, 0.2]));
    }

    public function testSearchMemoriesReturnsEmptyWhenUnavailable(): void
    {
        $this->settings->shouldReceive('get')->with('flarum-zai-bot.pgvector_host')->andReturn(null);

        $this->assertSame([], $this->service()->searchMemories(1, [0.1, 0.2]));
    }
}
