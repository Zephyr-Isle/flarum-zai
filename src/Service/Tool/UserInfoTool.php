<?php

namespace Zephyrisle\FlarumZaiBot\Service\Tool;

use Flarum\User\User;

class UserInfoTool implements ToolInterface
{
    public function getName(): string
    {
        return 'get_user_info';
    }

    public function getDescription(): string
    {
        return '查询用户的注册信息，包括昵称、用户名、注册时间、发帖数、用户组、以及认证状态（如果已安装认证插件）。参数可以是用户名或用户ID。';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'keyword' => [
                    'type' => 'string',
                    'description' => '用户名或用户ID',
                ],
            ],
            'required' => ['keyword'],
        ];
    }

    public function execute(array $args): string
    {
        $keyword = $args['keyword'] ?? '';

        if (is_numeric($keyword)) {
            $user = User::find((int) $keyword);
        } else {
            $user = User::where('username', $keyword)->first();
        }

        if (!$user) {
            return "未找到用户：{$keyword}";
        }

        $info = [
            '用户ID' => $user->id,
            '用户名' => $user->username,
            '昵称' => $user->display_name,
            '注册时间' => $user->joined_at ? $user->joined_at->format('Y-m-d H:i:s') : '未知',
            '最后活跃' => $user->last_seen_at ? $user->last_seen_at->format('Y-m-d H:i:s') : '未知',
            '发帖数' => $user->posts()->count(),
            '讨论数' => $user->discussions()->count(),
            '用户组' => $user->groups->pluck('name_singular')->implode(', ') ?: '无',
        ];

        if (class_exists(\Ramon\Verified\Models\UserVerification::class)) {
            $verification = \Ramon\Verified\Models\UserVerification::where('user_id', $user->id)->first();
            if ($verification && $verification->is_verified) {
                $info['认证状态'] = '已认证';
                $info['认证等级'] = $verification->verified_tier ?? '默认';
                $info['认证时间'] = $verification->verified_at ? $verification->verified_at->format('Y-m-d H:i:s') : '未知';
            } else {
                $info['认证状态'] = '未认证';
            }
        }

        $output = '';
        foreach ($info as $key => $value) {
            $output .= "- {$key}：{$value}\n";
        }

        return trim($output);
    }
}
