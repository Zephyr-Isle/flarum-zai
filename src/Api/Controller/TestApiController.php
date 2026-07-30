<?php

namespace Zephyrisle\FlarumZaiBot\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class TestApiController implements RequestHandlerInterface
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Client $client
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $body = $request->getParsedBody();
        $type = $body['type'] ?? 'llm';
        $customKeys = $body['keys'] ?? '';
        $customUrl = $body['url'] ?? '';

        $results = [];

        if ($type === 'llm' || $type === 'all') {
            $results['llm'] = $this->testLlm($customKeys, $customUrl);
        }

        if ($type === 'embedding' || $type === 'all') {
            $results['embedding'] = $this->testEmbedding($customKeys, $customUrl);
        }

        return new JsonResponse($results);
    }

    protected function testLlm(string $customKeys, string $customUrl): array
    {
        $apiUrl = $customUrl ?: $this->settings->get('flarum-zai-bot.api_url', 'https://api.openai.com/v1');
        $model = $this->settings->get('flarum-zai-bot.model', 'gpt-3.5-turbo');
        $keys = $customKeys ? array_map('trim', explode(',', $customKeys)) : $this->getLlmKeys();

        if (empty($keys)) {
            return ['success' => false, 'error' => 'No API keys configured'];
        }

        $lastError = null;
        foreach ($keys as $key) {
            try {
                $response = $this->client->post(rtrim($apiUrl, '/') . '/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $key,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'json' => [
                        'model' => $model,
                        'messages' => [['role' => 'user', 'content' => 'Reply OK']],
                        'max_tokens' => 10,
                    ],
                    'timeout' => 15,
                ]);

                $body = json_decode($response->getBody(), true);
                $reply = $body['choices'][0]['message']['content'] ?? '';

                return ['success' => true, 'model' => $model, 'reply' => trim($reply)];
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
            }
        }

        return ['success' => false, 'error' => $lastError ?: 'All keys failed'];
    }

    protected function testEmbedding(string $customKeys, string $customUrl): array
    {
        $apiUrl = $customUrl ?: $this->settings->get('flarum-zai-bot.embedding_api_url', '');
        if (!$apiUrl) {
            $apiUrl = $this->settings->get('flarum-zai-bot.api_url', 'https://api.openai.com/v1');
        }
        $model = $this->settings->get('flarum-zai-bot.embedding_model', 'text-embedding-3-small');
        $keys = $customKeys ? array_map('trim', explode(',', $customKeys)) : $this->getEmbeddingKeys();

        if (empty($keys)) {
            return ['success' => false, 'error' => 'No embedding API keys configured'];
        }

        $lastError = null;
        foreach ($keys as $key) {
            try {
                $response = $this->client->post(rtrim($apiUrl, '/') . '/embeddings', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $key,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'json' => [
                        'model' => $model,
                        'input' => 'Test',
                    ],
                    'timeout' => 15,
                ]);

                $body = json_decode($response->getBody(), true);
                $embedding = $body['data'][0]['embedding'] ?? null;

                return [
                    'success' => true,
                    'model' => $model,
                    'dimensions' => $embedding ? count($embedding) : 0,
                ];
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
            }
        }

        return ['success' => false, 'error' => $lastError ?: 'All keys failed'];
    }

    protected function getLlmKeys(): array
    {
        $raw = $this->settings->get('flarum-zai-bot.api_keys', '');
        return array_filter(array_map('trim', explode(',', $raw)));
    }

    protected function getEmbeddingKeys(): array
    {
        $raw = $this->settings->get('flarum-zai-bot.embedding_api_keys', '');
        $keys = array_filter(array_map('trim', explode(',', $raw)));
        if (!empty($keys)) {
            return $keys;
        }
        return $this->getLlmKeys();
    }
}
