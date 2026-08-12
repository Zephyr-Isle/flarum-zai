<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Zephyrisle\FlarumZaiBot\Service\EmbeddingService;
use Zephyrisle\FlarumZaiBot\Service\MemoryService;

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
        return new MemoryService($this->settings, new EmbeddingService($this->settings, $this->client));
    }

    /**
     * 独立 Embedding 配置（不再复用 LLM 供应商列表）。
     */
    protected function stubEmbeddingSettings(string $apiKey = 'jina-key'): void
    {
        $this->settings->shouldReceive('get')->andReturnUsing(function (string $key, mixed $default = null) use ($apiKey) {
            return match ($key) {
                'flarum-zai-bot.embedding_api_url' => 'https://api.jina.ai/v1',
                'flarum-zai-bot.embedding_api_key' => $apiKey,
                'flarum-zai-bot.embedding_model' => 'jina-embeddings-v3',
                default => $default,
            };
        });
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
            ->with('https://api.jina.ai/v1/embeddings', Mockery::on(function (array $options) {
                $payload = $options['json'] ?? [];
                // Jina 适配：v3 模型携带 task=text-matching 与显式 dimensions
                return ($payload['model'] ?? '') === 'jina-embeddings-v3'
                    && ($payload['task'] ?? '') === 'text-matching'
                    && ($payload['dimensions'] ?? 0) === 1024
                    && ($options['headers']['Authorization'] ?? '') === 'Bearer jina-key';
            }))
            ->andReturn(new Response(200, [], json_encode([
                'data' => [['embedding' => [0.1, 0.2, 0.3]]],
            ])));

        $embedding = $this->service()->generateEmbedding('测试文本');

        $this->assertSame([0.1, 0.2, 0.3], $embedding);
    }

    public function testGenerateEmbeddingReturnsNullWithoutKey(): void
    {
        $this->stubEmbeddingSettings('');

        $this->assertNull($this->service()->generateEmbedding('hello'));
    }

    public function testGenerateEmbeddingReturnsNullOnApiError(): void
    {
        $this->stubEmbeddingSettings();

        $this->client->shouldReceive('post')
            ->once()
            ->andThrow(new \Exception('Connection refused'));

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
