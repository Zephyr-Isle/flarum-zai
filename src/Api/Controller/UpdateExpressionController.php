<?php

namespace Zephyrisle\FlarumZaiBot\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zephyrisle\FlarumZaiBot\Service\ExpressionService;

/**
 * 表达学习审核：通过（启用）/ 禁用 / 删除 + 编辑可编辑字段。
 * 证据（evidence）与使用统计（use_count）只读，不在此接口接受修改。
 */
class UpdateExpressionController implements RequestHandlerInterface
{
    public function __construct(protected ExpressionService $expressions) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $id = (int) ($request->getAttribute('id') ?? 0);
        if ($id <= 0) {
            return new JsonResponse(['error' => 'invalid_id'], 422);
        }

        $body = $request->getParsedBody() ?? [];

        $action = $body['action'] ?? null;
        if ($action === 'approve') {
            return $this->expressions->approve($id)
                ? new JsonResponse(['ok' => true, 'status' => 'active'])
                : new JsonResponse(['error' => 'not_found'], 404);
        }

        if ($action === 'disable') {
            return $this->expressions->disable($id)
                ? new JsonResponse(['ok' => true, 'status' => 'disabled'])
                : new JsonResponse(['error' => 'not_found'], 404);
        }

        if ($action === 'delete') {
            return $this->expressions->delete($id)
                ? new JsonResponse(['ok' => true])
                : new JsonResponse(['error' => 'not_found'], 404);
        }

        $fields = [];
        foreach (['name', 'situation', 'template', 'syntax', 'recall_tags', 'scope'] as $key) {
            if (array_key_exists($key, $body)) {
                $fields[$key] = $body[$key];
            }
        }

        if ($fields === []) {
            return new JsonResponse(['error' => 'nothing_to_update'], 422);
        }

        return $this->expressions->updateFields($id, $fields)
            ? new JsonResponse(['ok' => true])
            : new JsonResponse(['error' => 'not_found'], 404);
    }
}
