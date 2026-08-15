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
 * 测试 Agnes AI API Key 连通性。
 *
 * 发送一个简单的文生图请求，验证 API Key 是否有效。
 */
class TestAgnesController implements RequestHandlerInterface
{
    private const AGNES_API_BASE = 'https://apihub.agnes-ai.cn/v1';

    public function __construct(
        protected Client $client,
        protected SettingsRepositoryInterface $settings
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $apiKey = $this->settings->get('flarum-zai-bot.agnes_api_key', '');

        if (empty($apiKey)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Agnes API Key 未配置',
            ]);
        }

        try {
            $response = $this->client->post(self::AGNES_API_BASE . '/images/generations', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => 'agnes-image-2.1-flash',
                    'prompt' => 'A simple test image: a red circle on white background',
                    'size' => '1K',
                    'ratio' => '1:1',
                    'extra_body' => [
                        'response_format' => 'url',
                    ],
                ],
                'timeout' => 30,
            ]);

            $body = json_decode((string) $response->getBody(), true);

            if (!empty($body['data'][0]['url'])) {
                return new JsonResponse([
                    'success' => true,
                    'message' => 'Agnes AI API Key 验证成功',
                    'model' => 'agnes-image-2.1-flash',
                ]);
            }

            return new JsonResponse([
                'success' => false,
                'error' => 'API 响应异常：未返回图片 URL',
            ]);
        } catch (\Exception $e) {
            error_log('[flarum-zai-bot] TestAgnes failed: ' . $e->getMessage());
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
