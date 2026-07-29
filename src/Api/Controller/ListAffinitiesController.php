<?php

namespace Zephyrisle\FlarumZaiBot\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zephyrisle\FlarumZaiBot\Model\BotAffinity;

class ListAffinitiesController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $affinities = BotAffinity::query()
            ->with('user')
            ->orderBy('total_score', 'desc')
            ->get()
            ->map(function (BotAffinity $aff) {
                return [
                    'user_id' => $aff->user_id,
                    'username' => $aff->user?->username ?? '已删除',
                    'display_name' => $aff->user?->display_name ?? '已删除',
                    'total_score' => $aff->total_score,
                    'chat_score' => $aff->chat_score,
                    'forum_score' => $aff->forum_score,
                    'interaction_count' => $aff->interaction_count,
                    'last_interaction_at' => $aff->last_interaction_at?->format('Y-m-d H:i:s'),
                ];
            });

        return new JsonResponse($affinities->values()->toArray());
    }
}
