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
        return '查询用户的完整资料，包括昵称、用户名、注册时间、最后活跃、发帖数、用户组、个人简介、生日、头像、背景图、以及认证状态。参数可以是用户名或用户ID。';
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

        if ($user->avatar_url) {
            $info['头像'] = $user->avatar_url;
        }

        if (class_exists(\FoF\UserBio\Event\BioChanged::class) && $user->bio) {
            $info['个人简介'] = strip_tags($user->bio);
        }

        if (class_exists(\Datlechin\Birthdays\AddBirthdayValidation::class) && $user->birthday) {
            $info['生日'] = $user->birthday;
            if ($user->showDobYear) {
                $info['显示出生年份'] = '是';
            }
        }

        if ($user->cover) {
            $info['背景图'] = $user->cover;
        }

        if (class_exists(\Ramon\Verified\TierResolver::class)) {
            $resolver = resolve(\Ramon\Verified\TierResolver::class);
            $isVerified = $resolver->isVerified($user);
            if ($isVerified) {
                $info['认证状态'] = '已认证';
                $verification = \Ramon\Verified\Models\UserVerification::where('user_id', $user->id)->first();
                if ($verification) {
                    $info['认证等级'] = $verification->verified_tier ?? '默认';
                    if ($verification->verified_at) {
                        $info['认证时间'] = $verification->verified_at->format('Y-m-d H:i:s');
                    }
                }
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
