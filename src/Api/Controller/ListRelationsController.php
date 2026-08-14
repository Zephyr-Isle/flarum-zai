<?php

namespace Zephyrisle\FlarumZaiBot\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zephyrisle\FlarumZaiBot\Api\Controller\Concerns\CatchesMissingTables;
use Zephyrisle\FlarumZaiBot\Model\BotRelation;

class ListRelationsController implements RequestHandlerInterface
{
    use CatchesMissingTables;

    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->guardDb(function () use ($request) {
            $actor = RequestUtil::getActor($request);
            $actor->assertAdmin();

            $query = $request->getQueryParams();
            $page = max(1, (int) ($query['page'] ?? 1));
            $limit = max(1, min(self::MAX_LIMIT, (int) ($query['limit'] ?? self::DEFAULT_LIMIT)));

            $queryBuilder = BotRelation::query()->with('user');

            $search = trim((string) ($query['q'] ?? ''));
            if ($search !== '') {
                $matchedUsers = User::where('username', 'like', '%' . $search . '%')
                    ->orWhere('display_name', 'like', '%' . $search . '%')
                    ->pluck('id')
                    ->toArray();

                if ($matchedUsers === []) {
                    $matchedUsers = [-1];
                }

                $queryBuilder->whereIn('user_id', $matchedUsers);
            }

            $total = (clone $queryBuilder)->count();

            $lastPage = max(1, (int) ceil($total / $limit));
            $page = min($page, $lastPage);

            $items = $queryBuilder
                ->orderByDesc('updated_at')
                ->offset(($page - 1) * $limit)
                ->limit($limit)
                ->get()
                ->map(function (BotRelation $relation) {
                    return [
                        'user_id' => $relation->user_id,
                        'username' => $relation->user?->username ?? '已删除',
                        'display_name' => $relation->user?->display_name ?? '已删除',
                        'identity' => $relation->identity ?? null,
                        'aliases' => $relation->aliases ?? [],
                        'group_profile' => $relation->group_profile ?? null,
                        'boundaries' => $relation->boundaries ?? [],
                        'pending_observations' => $relation->pending_observations ?? [],
                    ];
                });

            return new JsonResponse([
                'items' => $items->values()->toArray(),
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
            ]);
        });
    }
}
