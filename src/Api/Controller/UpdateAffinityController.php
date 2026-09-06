<?php

namespace Zephyrisle\FlarumZaiBot\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zephyrisle\FlarumZaiBot\Api\Controller\Concerns\CatchesMissingTables;
use Zephyrisle\FlarumZaiBot\Model\BotAffinity;

/**
 * 后台好感度管理：PATCH /api/zai-bot/affinities/{userId}
 *
 * Body（JSON，字段均可选）：{
 *   favor: -100~100（好感度主值）,
 *   trust: -100~100,
 *   intimacy: -100~100,
 *   emotions: {joy: -100~100, ...}（覆盖单维或整体）,
 *   attitude: string,
 *   relationship: string,
 *   blacklisted: bool（手动黑名单开关）,
 *   reset: bool（一键重置全部状态）
 * }
 */
class UpdateAffinityController implements RequestHandlerInterface
{
    use CatchesMissingTables;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->guardDb(function () use ($request) {
            $actor = RequestUtil::getActor($request);
            $actor->assertAdmin();

            // 路由参数在 Flarum 中存放在 routeParameters 属性（并合并进 query params），
            // 不能直接 getAttribute('userId')，否则恒为 null 导致 invalid_user。
            $routeParams = $request->getAttribute('routeParameters') ?? [];
            $userId = (int) ($routeParams['userId'] ?? $request->getQueryParams()['userId'] ?? $request->getAttribute('userId') ?? 0);
            $body = $request->getParsedBody() ?? [];

            if ($userId <= 0) {
                return new JsonResponse(['ok' => false, 'error' => 'invalid_user'], 400);
            }

            $affinity = BotAffinity::getOrCreate($userId);

            if (!empty($body['reset'])) {
                $affinity->reset();
            } else {
                if (array_key_exists('favor', $body)) {
                    $affinity->setScore((int) $body['favor']);
                }
                if (array_key_exists('trust', $body)) {
                    $affinity->setTrust((int) $body['trust']);
                }
                if (array_key_exists('intimacy', $body)) {
                    $affinity->setIntimacy((int) $body['intimacy']);
                }
                if (array_key_exists('emotions', $body) && is_array($body['emotions'])) {
                    foreach ($body['emotions'] as $key => $value) {
                        $affinity->setEmotion((string) $key, (int) $value);
                    }
                }
                if (array_key_exists('attitude', $body)) {
                    $affinity->setAttitude((string) $body['attitude']);
                }
                if (array_key_exists('relationship', $body)) {
                    $affinity->setRelationship((string) $body['relationship']);
                }
                if (array_key_exists('blacklisted', $body)) {
                    (bool) $body['blacklisted'] ? $affinity->blacklist() : $affinity->unblacklist();
                }
            }

            return new JsonResponse(['ok' => true]);
        });
    }
}
