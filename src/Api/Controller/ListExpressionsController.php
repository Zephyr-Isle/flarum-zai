<?php

namespace Zephyrisle\FlarumZaiBot\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zephyrisle\FlarumZaiBot\Model\BotExpression;
use Zephyrisle\FlarumZaiBot\Service\ExpressionService;

class ListExpressionsController implements RequestHandlerInterface
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function __construct(protected ExpressionService $expressions) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $query = $request->getQueryParams();
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = max(1, min(self::MAX_LIMIT, (int) ($query['limit'] ?? self::DEFAULT_LIMIT)));
        $status = trim((string) ($query['status'] ?? ''));
        $q = trim((string) ($query['q'] ?? ''));

        $result = $this->expressions->list($status !== '' ? $status : null, $q, $page, $limit);

        $lastPage = max(1, (int) ceil($result['total'] / $limit));
        $page = min($page, $lastPage);

        $items = collect($result['items'])->map(function (BotExpression $e) {
            return [
                'id' => $e->id,
                'name' => $e->name,
                'status' => $e->status,
                'source_type' => $e->source_type,
                'situation' => $e->situation ?? null,
                'template' => $e->template,
                'syntax' => $e->syntax ?? null,
                'recall_tags' => $e->recall_tags ?? [],
                'scope' => $e->scope ?? [],
                'evidence' => $e->evidence ?? [],
                'use_count' => (int) ($e->use_count ?? 0),
                'created_at' => $e->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $e->updated_at?->format('Y-m-d H:i:s'),
            ];
        });

        return new JsonResponse([
            'items' => $items->values()->toArray(),
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit,
            'counts' => $result['counts'],
        ]);
    }
}
