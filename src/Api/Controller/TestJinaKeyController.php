<?php

namespace Zephyrisle\FlarumZaiBot\Api\Controller;

use Flarum\Http\Controller\ControllerInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * 测试 Jina API Key 是否有效。
 *
 * POST /api/zai-bot/jina/test-key
 * Body: { "api_key": "xxx" }
 */
class TestJinaKeyController implements ControllerInterface
{
    public function __construct(
        protected Client $client
    ) {}

    public function handle(ServerRequestInterface $request): JsonResponse
    {
        $body = (array) $request->getParsedBody();
        $apiKey = trim($body['api_key'] ?? '');

        if ($apiKey === '') {
            return new JsonResponse(['valid' => false, 'error' => 'API Key 不能为空'], 400);
        }

        try {
            // 测试 Embedding API
            $response = $this->client->post('https://api.jina.ai/v1/embeddings', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => 'jina-embeddings-v3',
                    'input' => 'test',
                ],
                'timeout' => 10,
            ]);

            $result = json_decode((string) $response->getBody(), true);

            if (isset($result['data'][0]['embedding'])) {
                // 尝试获取余额信息
                $balance = null;
                try {
                    $authResponse = $this->client->get('https://api.jina.ai/v1/auth/token', [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $apiKey,
                        ],
                        'timeout' => 5,
                    ]);
                    $authBody = json_decode((string) $authResponse->getBody(), true);
                    $balance = $authBody['balance'] ?? $authBody['tokens'] ?? null;
                } catch (\Exception $e) {
                    // 余额查询失败不影响验证结果
                }

                return new JsonResponse([
                    'valid' => true,
                    'balance' => $balance,
                    'message' => 'API Key 有效',
                ]);
            }

            return new JsonResponse([
                'valid' => false,
                'error' => 'API Key 无效：未返回有效的 embedding',
            ], 422);
        } catch (GuzzleException $e) {
            $statusCode = $e->getResponse()?->getStatusCode() ?? 0;

            if ($statusCode === 401 || $statusCode === 403) {
                return new JsonResponse([
                    'valid' => false,
                    'error' => 'API Key 无效或已过期',
                ], 422);
            }

            return new JsonResponse([
                'valid' => false,
                'error' => '验证失败：' . $e->getMessage(),
            ], 500);
        }
    }
}
