<?php

namespace Zephyrisle\FlarumZaiBot\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zephyrisle\FlarumZaiBot\Service\MemoryService;

/**
 * 后台记忆管理列表：GET /api/zai-bot/memories
 *
 * 参数：page / limit / q（内容关键词）/ user（用户 ID 或用户名）/ include_archived
 * 依赖外部 pgvector 数据库（MemoryService::isAvailable），未配置时返回空列表。
 */
class ListMemoriesController implements RequestHandlerInterface
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function __construct(protected MemoryService $memory)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $query = $request->getQueryParams();
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = max(1, min(self::MAX_LIMIT, (int) ($query['limit'] ?? self::DEFAULT_LIMIT)));
        $q = trim((string) ($query['q'] ?? ''));
        $includeArchived = in_array(($query['include_archived'] ?? '0'), ['1', 'true', 'yes'], true);

        $userId = null;
        $userParam = trim((string) ($query['user'] ?? ''));
        if ($userParam !== '') {
            if (ctype_digit($userParam)) {
                $userId = (int) $userParam;
            } else {
                $user = User::where('username', $userParam)
                    ->orWhere('display_name', $userParam)
                    ->first();
                $userId = $user ? $user->id : -1; // -1：无匹配用户 → 空结果
            }
        }

        $result = $this->memory->listMemories($page, $limit, $userId, $q, $includeArchived);

        $userIds = array_values(array_unique(array_filter(array_map(
            fn ($item) => (int) ($item['user_id'] ?? 0),
            $result['items']
        ))));

        $users = [];
        if ($userIds !== []) {
            $users = User::whereIn('id', $userIds)->get()->keyBy('id');
        }

        $items = array_map(function (array $item) use ($users) {
            $uid = (int) $item['user_id'];
            $user = $users[$uid] ?? null;

            return [
                'id' => $item['id'],
                'user_id' => $uid,
                'username' => $user?->username ?? '已删除',
                'display_name' => $user?->display_name ?? '已删除',
                'content' => $item['content'] ?? '',
                'created_at' => $item['created_at'] ?? null,
                'importance' => $item['importance'] ?? 0,
                'reinforce_count' => $item['reinforce_count'] ?? 0,
                'last_accessed_at' => $item['last_accessed_at'] ?? null,
                'ttl_days' => $item['ttl_days'] ?? null,
                'expires_at' => $item['expires_at'] ?? null,
                'archived' => $item['archived'] ?? false,
                'source_text' => $item['source_text'] ?? null,
                'source_meta' => $item['source_meta'] ?? null,
            ];
        }, $result['items']);

        return new JsonResponse([
            'items' => array_values($items),
            'total' => $result['total'],
            'page' => $result['page'] ?? $page,
            'limit' => $result['limit'] ?? $limit,
            'available' => $this->memory->isAvailable(),
        ]);
    }
}
