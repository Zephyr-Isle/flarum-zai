<?php

namespace Zephyrisle\FlarumZaiBot\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zephyrisle\FlarumZaiBot\Api\Controller\Concerns\CatchesMissingTables;
use Zephyrisle\FlarumZaiBot\Service\RelationService;

class UpdateRelationController implements RequestHandlerInterface
{
    use CatchesMissingTables;

    public function __construct(protected RelationService $relations) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->guardDb(function () use ($request) {
            $actor = RequestUtil::getActor($request);
            $actor->assertAdmin();

            $userId = (int) ($request->getAttribute('userId') ?? 0);
            if ($userId <= 0) {
                return new JsonResponse(['error' => 'invalid_user'], 422);
            }

            $body = $request->getParsedBody() ?? [];

            // 待确认观察：确认/驳回（按索引）
            $action = $body['action'] ?? null;
            if ($action === 'confirm_observation' || $action === 'reject_observation') {
                $index = (int) ($body['index'] ?? -1);
                $ok = $action === 'confirm_observation'
                    ? $this->relations->confirmObservation($userId, $index)
                    : $this->relations->rejectObservation($userId, $index);

                if (!$ok) {
                    return new JsonResponse(['error' => 'observation_not_found'], 404);
                }

                return new JsonResponse(['ok' => true]);
            }

            $fields = [];
            foreach (['identity', 'aliases', 'group_profile', 'boundaries'] as $key) {
                if (array_key_exists($key, $body)) {
                    $fields[$key] = $body[$key];
                }
            }

            if ($fields === []) {
                return new JsonResponse(['error' => 'nothing_to_update'], 422);
            }

            $relation = $this->relations->update($userId, $fields);

            return new JsonResponse([
                'ok' => true,
                'relation' => [
                    'user_id' => $relation->user_id,
                    'identity' => $relation->identity ?? null,
                    'aliases' => $relation->aliases ?? [],
                    'group_profile' => $relation->group_profile ?? null,
                    'boundaries' => $relation->boundaries ?? [],
                    'pending_observations' => $relation->pending_observations ?? [],
                ],
            ]);
        });
    }
}
