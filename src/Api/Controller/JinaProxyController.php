<?php

namespace Zephyrisle\FlarumZaiBot\Api\Controller;

use Flarum\Http\RequestUtil;
use GuzzleHttp\Client;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class JinaProxyController implements RequestHandlerInterface
{
    public function __construct(
        protected Client $client
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $action = $request->getQueryParams()['action'] ?? '';
        $query = $request->getQueryParams()['q'] ?? '';
        $url = $request->getQueryParams()['url'] ?? '';

        if ($action === 'search' && $query) {
            return $this->proxySearch($query);
        }

        if ($action === 'read' && $url) {
            return $this->proxyRead($url);
        }

        return new JsonResponse(['error' => 'Invalid params. Use ?action=search&q=... or ?action=read&url=...'], 400);
    }

    protected function proxySearch(string $query): JsonResponse
    {
        try {
            $response = $this->client->get('https://s.jinaai.cn/' . urlencode($query), [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 20,
            ]);
            $body = json_decode($response->getBody(), true);
            return new JsonResponse($body);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 502);
        }
    }

    protected function proxyRead(string $url): JsonResponse
    {
        try {
            // PHP 解析 query 参数时已解码一次，这里直接使用，避免二次解码损坏 URL
            $response = $this->client->get('https://r.jinaai.cn/' . $url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Return-Format' => 'markdown',
                ],
                'timeout' => 30,
            ]);
            $body = json_decode($response->getBody(), true);
            return new JsonResponse($body);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 502);
        }
    }
}
