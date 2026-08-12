<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Zephyrisle\FlarumZaiBot\Service\EmbeddingService;

class EmbeddingServiceTest extends TestCase
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

    protected function service(): EmbeddingService
    {
        return new EmbeddingService($this->settings, $this->client);
    }

    public function testDefaultsAreJina(): void
    {
        $this->settings->shouldReceive('get')->andReturnUsing(function (string $key, mixed $default = null) {
            return $default;
        });

        $service = $this->service();

        $this->assertSame('https://api.jina.ai/v1', $service->apiUrl());
        $this->assertSame('jina-embeddings-v3', $service->model());
        $this->assertSame(1024, EmbeddingService::DEFAULT_DIMENSIONS);
        $this->assertFalse($service->isConfigured());
    }

    public function testPayloadAddsJinaParamsForV3Model(): void
    {
        $this->settings->shouldReceive('get')->andReturnUsing(function (string $key, mixed $default = null) {
            return match ($key) {
                'flarum-zai-bot.embedding_model' => 'jina-embeddings-v3',
                default => $default,
            };
        });

        $payload = $this->service()->payload('测试');

        $this->assertSame('jina-embeddings-v3', $payload['model']);
        $this->assertSame('测试', $payload['input']);
        $this->assertSame('text-matching', $payload['task']);
        $this->assertSame(1024, $payload['dimensions']);
    }

    public function testPayloadOmitsJinaParamsForNonJinaModel(): void
    {
        $this->settings->shouldReceive('get')->andReturnUsing(function (string $key, mixed $default = null) {
            return match ($key) {
                'flarum-zai-bot.embedding_model' => 'text-embedding-3-small',
                default => $default,
            };
        });

        $payload = $this->service()->payload('hi');

        $this->assertSame('text-embedding-3-small', $payload['model']);
        $this->assertArrayNotHasKey('task', $payload);
        $this->assertArrayNotHasKey('dimensions', $payload);
    }

    public function testGenerateEmbeddingReturnsNullWhenNotConfigured(): void
    {
        $this->settings->shouldReceive('get')->with('flarum-zai-bot.embedding_api_key', '')->andReturn('');

        $this->assertNull($this->service()->generateEmbedding('hello'));
    }

    public function testGenerateEmbeddingPostsToJinaEndpoint(): void
    {
        $this->settings->shouldReceive('get')->andReturnUsing(function (string $key, mixed $default = null) {
            return match ($key) {
                'flarum-zai-bot.embedding_api_url' => 'https://api.jina.ai/v1',
                'flarum-zai-bot.embedding_api_key' => 'sk-jina',
                'flarum-zai-bot.embedding_model' => 'jina-embeddings-v3',
                default => $default,
            };
        });

        $this->client->shouldReceive('post')
            ->once()
            ->with('https://api.jina.ai/v1/embeddings', Mockery::on(function (array $options) {
                return ($options['headers']['Authorization'] ?? '') === 'Bearer sk-jina'
                    && ($options['json']['input'] ?? '') === 'hello';
            }))
            ->andReturn(new Response(200, [], json_encode([
                'data' => [['embedding' => [0.5, 0.25, 0.125]]],
                'model' => 'jina-embeddings-v3',
            ])));

        $embedding = $this->service()->generateEmbedding('hello');

        $this->assertSame([0.5, 0.25, 0.125], $embedding);
    }

    public function testGenerateEmbeddingReturnsNullOnEmptyResponse(): void
    {
        $this->settings->shouldReceive('get')->andReturnUsing(function (string $key, mixed $default = null) {
            return match ($key) {
                'flarum-zai-bot.embedding_api_key' => 'sk-jina',
                'flarum-zai-bot.embedding_model' => 'jina-embeddings-v3',
                default => $default,
            };
        });

        $this->client->shouldReceive('post')
            ->once()
            ->andReturn(new Response(200, [], json_encode(['data' => []])));

        $this->assertNull($this->service()->generateEmbedding('hello'));
    }
}
