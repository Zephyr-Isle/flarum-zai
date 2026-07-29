<?php

namespace Zephyrisle\FlarumZaiBot\Service\Tool;

use Flarum\User\User;

class ViewFileTool implements ToolInterface
{
    public function getName(): string
    {
        return 'view_user_files';
    }

    public function getDescription(): string
    {
        return '查看用户上传的文件（文本文件和小图片）。可以列出用户的所有可访问文件，或按关键词搜索。返回文件名称、大小、类型和URL。';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'username' => [
                    'type' => 'string',
                    'description' => '用户名，留空则列出当前登录用户的文件',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => '搜索关键词（可选），按文件名筛选',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => '返回结果数量（默认10，最大20）',
                ],
            ],
        ];
    }

    public function execute(array $args): string
    {
        if (!class_exists(\FoF\Upload\File::class)) {
            return '未安装 fof/upload 扩展，无法查看文件。';
        }

        $username = $args['username'] ?? '';
        $query = $args['query'] ?? '';
        $limit = min((int) ($args['limit'] ?? 10), 20);

        $user = null;
        if ($username) {
            $user = User::where('username', $username)->first();
            if (!$user) {
                return "未找到用户：{$username}";
            }
        }

        $filesQuery = \FoF\Upload\File::query()
            ->where('hidden', false);

        if ($user) {
            $filesQuery->where('actor_id', $user->id);
        }

        if ($query) {
            $filesQuery->where('base_name', 'like', "%{$query}%");
        }

        $textMimePrefixes = ['text/', 'application/json', 'application/xml', 'application/javascript'];
        $imageMimePrefixes = ['image/'];

        $filesQuery->where(function ($q) use ($textMimePrefixes, $imageMimePrefixes) {
            foreach ($textMimePrefixes as $prefix) {
                $q->orWhere('type', 'like', "{$prefix}%");
            }
            foreach ($imageMimePrefixes as $prefix) {
                $q->orWhere('type', 'like', "{$prefix}%");
            }
        });

        $files = $filesQuery->orderBy('created_at', 'desc')
            ->take($limit)
            ->get();

        if ($files->isEmpty()) {
            $userLabel = $user ? "用户 {$user->display_name}" : '当前用户';
            return "{$userLabel}没有可查看的已上传文件。";
        }

        $output = $user ? "用户 {$user->display_name} 的上传文件：\n" : "最近可查看的上传文件：\n";
        foreach ($files as $i => $file) {
            $owner = $file->actor ? $file->actor->display_name : '未知';
            $output .= ($i + 1) . ". {$file->base_name}";
            $output .= "（类型：{$file->type}，大小：{$file->humanSize}";
            if ($user) {
                $output .= "，上传者：{$owner}";
            }
            $output .= "）\n";
            if ($file->url) {
                $output .= "   链接：{$file->url}\n";
            }
        }

        return trim($output);
    }
}
