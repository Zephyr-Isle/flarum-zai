<?php

namespace Zephyrisle\FlarumZaiBot\Api\Controller;

use Flarum\Http\RequestUtil;
use GuzzleHttp\Client;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zephyrisle\FlarumZaiBot\Service\EmbeddingService;
use Zephyrisle\FlarumZaiBot\Service\ProviderService;

class TestApiController implements RequestHandlerInterface
{
    public function __construct(
        protected Client $client,
        protected ProviderService $providers,
        protected EmbeddingService $embeddings
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

    /**
     * 返回结构：{ success: 是否有任意端点成功, items: [{name, model, success, reply|error}, ...] }
     */
    protected function testLlm(string $customKeys, string $customUrl): array
    {
        $endpoints = $this->endpointsForTest($customKeys, $customUrl);

        if (empty($endpoints)) {
            return ['success' => false, 'error' => 'No API keys configured', 'items' => []];
        }

        $items = [];
        foreach ($endpoints as $endpoint) {
            try {
                $response = $this->client->post(rtrim($endpoint['api_url'], '/') . '/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $endpoint['api_key'],
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'json' => [
                        'model' => $endpoint['model'],
                        'messages' => [['role' => 'user', 'content' => 'Reply OK']],
                        'max_tokens' => 10,
                    ],
                    'timeout' => 15,
                ]);

                $body = json_decode($response->getBody(), true);
                $reply = $body['choices'][0]['message']['content'] ?? '';

                $items[] = [
                    'name' => $endpoint['name'],
                    'model' => $endpoint['model'],
                    'success' => true,
                    'reply' => trim($reply),
                ];
            } catch (\Exception $e) {
                $items[] = [
                    'name' => $endpoint['name'],
                    'model' => $endpoint['model'],
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'success' => (bool) array_filter($items, fn ($i) => $i['success']),
            'items' => $items,
        ];
    }

    /**
     * Embedding 使用独立的 EmbeddingService 配置（不与 LLM 供应商同步），
     * 完全适配 Jina AI；也可传入自定义 url/keys 做临时测试。
     */
    protected function testEmbedding(string $customKeys, string $customUrl): array
    {
        $custom = $customKeys !== '' || $customUrl !== '';
        $url = $customUrl !== '' ? rtrim($customUrl, '/') : $this->embeddings->apiUrl();
        $keys = $customKeys !== '' ? array_values(array_filter(array_map('trim', explode(',', $customKeys)))) : [];
        $key = $keys[0] ?? $this->embeddings->apiKey();

        if ($key === '') {
            return ['success' => false, 'error' => 'No embedding API key configured', 'items' => []];
        }

        $name = $custom ? 'Custom' : 'Embedding';
        $items = [];

        try {
            $response = $this->client->post($url . '/embeddings', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $key,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => $this->embeddings->payload('Test'),
                'timeout' => 15,
            ]);

            $body = json_decode($response->getBody(), true);
            $embedding = $body['data'][0]['embedding'] ?? null;

            $items[] = [
                'name' => $name,
                'model' => $this->embeddings->model(),
                'success' => true,
                'dimensions' => $embedding ? count($embedding) : 0,
            ];
        } catch (\Exception $e) {
            $items[] = [
                'name' => $name,
                'model' => $this->embeddings->model(),
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }

        return [
            'success' => (bool) array_filter($items, fn ($i) => $i['success']),
            'items' => $items,
        ];
    }

    /**
     * 自定义测试（给了 keys 或 url）时构建临时 LLM 端点列表；否则使用已配置的 LLM 供应商端点。
     */
    protected function endpointsForTest(string $customKeys, string $customUrl): array
    {
        if ($customKeys !== '' || $customUrl !== '') {
            // 旧版 api_url / model 设置已删除，自定义测试使用硬编码默认值
            $url = rtrim($customUrl ?: 'https://api.openai.com/v1', '/');
            $model = 'gpt-4o-mini';

            $keys = $customKeys !== '' ? array_values(array_filter(array_map('trim', explode(',', $customKeys)))) : [];
            if (empty($keys)) {
                // 只给了 URL 没给密钥：复用已配置的 LLM 端点密钥
                $keys = array_column($this->providers->chatEndpoints(), 'api_key');
            }

            return array_map(fn (string $key) => [
                'name' => 'Custom',
                'api_url' => $url,
                'api_key' => $key,
                'model' => $model,
            ], $keys);
        }

        return $this->providers->chatEndpoints();
    }
}
