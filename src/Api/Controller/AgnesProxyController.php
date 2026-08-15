<?php

namespace Zephyrisle\FlarumZaiBot\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Agnes AI API 代理控制器。
 *
 * 通过服务器代理 Agnes AI 的图片/视频生成 API，
 * 避免前端直接暴露 API Key。
 */
class AgnesProxyController implements RequestHandlerInterface
{
    private const AGNES_API_BASE = 'https://apihub.agnes-ai.com';

    public function __construct(
        protected Client $client,
        protected SettingsRepositoryInterface $settings
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $path = $request->getAttribute('path', '');
        $method = $request->getMethod();
        $body = $request->getParsedBody() ?? [];

        $apiKey = $this->settings->get('flarum-zai-bot.agnes_api_key', '');
        if (empty($apiKey)) {
            return new JsonResponse(['error' => 'Agnes API key not configured'], 400);
        }

        try {
            $url = self::AGNES_API_BASE . '/' . ltrim($path, '/');

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'timeout' => 60,
            ];

            if ($method === 'POST' && !empty($body)) {
                $options['json'] = $body;
            }

            $response = $this->client->request($method, $url, $options);
            $responseBody = json_decode((string) $response->getBody(), true);

            return new JsonResponse($responseBody, $response->getStatusCode());
        } catch (\Exception $e) {
            error_log('[flarum-zai-bot] AgnesProxy failed: ' . $e->getMessage());
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}
