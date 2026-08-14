<?php

namespace Zephyrisle\FlarumZaiBot\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zephyrisle\FlarumZaiBot\Api\Controller\Concerns\CatchesMissingTables;
use Zephyrisle\FlarumZaiBot\Model\BotAffinity;

class ListAffinitiesController implements RequestHandlerInterface
{
    use CatchesMissingTables;

    /**
     * 默认与上限（每页条数）。
     */
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

            $queryBuilder = BotAffinity::query()->with('user');

            // 用户名/昵称搜索：命中用户列表后按 user_id 过滤
            $search = trim((string) ($query['q'] ?? ''));
            if ($search !== '') {
                $matchedUsers = User::where('username', 'like', '%' . $search . '%')
                    ->orWhere('display_name', 'like', '%' . $search . '%')
                    ->pluck('id')
                    ->toArray();

                if ($matchedUsers === []) {
                    $matchedUsers = [-1]; // 无匹配 → 空结果
                }

                $queryBuilder->whereIn('user_id', $matchedUsers);
            }

            $total = (clone $queryBuilder)->count();

            // 钳制到最后一页，避免超出范围的页码返回空列表（前端会误显示"暂无记录"）
            $lastPage = max(1, (int) ceil($total / $limit));
            $page = min($page, $lastPage);

            $items = $queryBuilder
                ->orderBy('total_score', 'desc')
                ->orderBy('id', 'desc')
                ->offset(($page - 1) * $limit)
                ->limit($limit)
                ->get()
                ->map(function (BotAffinity $aff) {
                    return [
                        'user_id' => $aff->user_id,
                        'username' => $aff->user?->username ?? '已删除',
                        'display_name' => $aff->user?->display_name ?? '已删除',
                        'total_score' => $aff->total_score,
                        'trust' => $aff->trust ?? 0,
                        'intimacy' => $aff->intimacy ?? 0,
                        'emotions' => $aff->emotions ?? [],
                        'attitude' => $aff->attitude ?? null,
                        'relationship' => $aff->relationship ?? null,
                        'blacklisted' => (bool) ($aff->blacklisted ?? false),
                        'interaction_count' => $aff->interaction_count,
                        'last_interaction_at' => $aff->last_interaction_at?->format('Y-m-d H:i:s'),
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
