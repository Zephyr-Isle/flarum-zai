<?php

namespace Zephyrisle\FlarumZaiBot\Job\Concerns;

use Carbon\Carbon;
use Flarum\User\User;
use Illuminate\Support\Str;

/**
 * 机器人用户的公共处理逻辑，供论坛回复与私信回复两个 Job 共用。
 * （API 密钥的解析、轮询与回退已统一收归 ProviderService。）
 */
trait ManagesBotUser
{
    protected function getBotUser(string $botUsername): User
    {
        $botUser = User::where('username', $botUsername)->first();

        if (!$botUser) {
            $botUser = new User();
            $botUser->username = $botUsername;
            $botUser->email = $botUsername . '@bot.local';
            $botUser->password = Str::random(40);
            $botUser->is_email_confirmed = true;
            $botUser->save();
            // 普通成员组（组 2），不给管理员权限
            $botUser->groups()->sync([2]);
        } elseif (in_array(1, array_map('intval', $botUser->groups()->pluck('id')->all()), true)) {
            // 旧版本创建的 bot 可能被误设为管理员组（组 1），自动降级为普通成员。
            // 用 in_array 判断，即使 bot 同时属于多个组（含管理员）也会被正确降级；
            // array_map('intval') 防止不同驱动下 ID 以字符串返回导致严格比较失败。
            $botUser->groups()->sync([2]);
        }

        $botUser->last_seen_at = Carbon::now();
        $botUser->save();

        return $botUser;
    }
}
