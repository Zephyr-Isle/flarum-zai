<?php

namespace Zephyrisle\FlarumZaiBot\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zephyrisle\FlarumZaiBot\Service\MemoryService;

/**
 * 后台记忆管理操作：PATCH /api/zai-bot/memories/{id}
 *
 * Body（JSON）：{
 *   importance: 0-10（可选，修改重要度）,
 *   ttl_days: 天数（可选，0 移除 TTL；负值/缺省表示不修改）,
 *   action: "archive" | "restore" | "delete"（可选，一次性操作）
 * }
 * 内容与向量为只读（避免文本与 embedding 不一致）。
 */
class UpdateMemoryController implements RequestHandlerInterface
{
    public function __construct(protected MemoryService $memory)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        // 路由参数在 Flarum 中存放在 routeParameters 属性（并合并进 query params），
        // 不能直接 getAttribute('id')，否则恒为 null 导致 invalid_id。
        $routeParams = $request->getAttribute('routeParameters') ?? [];
        $id = (int) ($routeParams['id'] ?? $request->getQueryParams()['id'] ?? $request->getAttribute('id') ?? 0);
        $body = $request->getParsedBody() ?? [];

        if ($id <= 0) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_id'], 400);
        }

        if (!$this->memory->isAvailable()) {
            return new JsonResponse(['ok' => false, 'error' => 'memory_disabled'], 400);
        }

        $action = (string) ($body['action'] ?? '');
        $ok = match ($action) {
            'archive' => $this->memory->archiveMemory($id),
            'restore' => $this->memory->restoreMemory($id),
            'delete' => $this->memory->deleteMemory($id),
            default => null,
        };

        if ($ok === null || $ok) {
            if (array_key_exists('importance', $body) || array_key_exists('ttl_days', $body)) {
                $importance = array_key_exists('importance', $body) ? (int) $body['importance'] : null;
                $ttlDays = array_key_exists('ttl_days', $body) ? (int) $body['ttl_days'] : null;
                $this->memory->updateMemoryFields($id, $importance, $ttlDays);
            }
        }

        if ($ok === false) {
            return new JsonResponse(['ok' => false, 'error' => 'not_found_or_failed'], 404);
        }

        return new JsonResponse(['ok' => true]);
    }
}
