<?php

namespace Zephyrisle\FlarumZaiBot\Api\Controller\Concerns;

use Illuminate\Database\QueryException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * 数据表缺失保护：扩展升级后若尚未执行 php flarum migrate，
 * 新表不存在会抛 QueryException 导致整个后台接口 500 崩溃。
 * 这里把异常转换成带友好提示的 503 JSON，管理员刷新页面、
 * 执行迁移后即可恢复，避免后台页面打不开。
 */
trait CatchesMissingTables
{
    protected function guardDb(\Closure $fn): ResponseInterface
    {
        try {
            return $fn();
        } catch (QueryException $e) {
            error_log('[flarum-zai-bot] DB query failed (missing table / pending migration): ' . $e->getMessage());

            return new JsonResponse([
                'error' => '数据库表尚未创建：请先通过 SSH 在站点目录执行 php flarum migrate 完成迁移后再刷新本页。',
            ], 503);
        }
    }
}
