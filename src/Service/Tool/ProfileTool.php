<?php

namespace Zephyrisle\FlarumZaiBot\Service\Tool;

use Flarum\Http\AccessToken;
use Flarum\Http\SessionAccessToken;
use Flarum\User\User;
use GuzzleHttp\Client;

/**
 * 个人资料管理工具：修改机器人自己的头像、背景图和简介。
 *
 * 所有操作仅限修改机器人自己的资料，不允许修改其他用户。
 *
 * $apiUrl 为论坛自身的 API 根地址（如 https://forum.example/api），
 * 由 BuildsBotTools 通过 UrlGenerator 传入；文件上传需要机器人用户的
 * Access Token（每次上传临时生成、用后即删）。
 */
class ProfileTool implements ToolInterface
{
    public function __construct(
        protected int $botUserId,
        protected Client $client,
        protected string $apiUrl
    ) {}

    public function getName(): string
    {
        return 'update_profile';
    }

    public function getDescription(): string
    {
        return '修改机器人自己的个人资料（头像、背景图、简介）。仅能修改自己的资料，不能修改其他用户。';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'description' => '操作类型：set_avatar（设置头像）、set_cover（设置背景图）、set_bio（设置简介）、clear_avatar（清除头像）、clear_cover（清除背景图）',
                    'enum' => ['set_avatar', 'set_cover', 'set_bio', 'clear_avatar', 'clear_cover'],
                ],
                'url' => [
                    'type' => 'string',
                    'description' => '图片URL（action为set_avatar或set_cover时必填，需为http(s)链接）',
                ],
                'bio' => [
                    'type' => 'string',
                    'description' => '简介内容（action为set_bio时必填）',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $args): string
    {
        $action = $args['action'] ?? '';
        $url = $args['url'] ?? '';
        $bio = $args['bio'] ?? '';

        switch ($action) {
            case 'set_avatar':
                return $this->setAvatar($url);
            case 'set_cover':
                return $this->setCover($url);
            case 'set_bio':
                return $this->setBio($bio);
            case 'clear_avatar':
                return $this->clearAvatar();
            case 'clear_cover':
                return $this->clearCover();
            default:
                return '未知操作：' . $action;
        }
    }

    protected function setAvatar(string $url): string
    {
        if (empty($url)) {
            return '请提供头像图片的URL。';
        }

        if (!preg_match('#^https?://#i', $url)) {
            return 'URL必须以 http:// 或 https:// 开头。';
        }

        try {
            // 下载图片
            $imageContent = $this->downloadImage($url);
            if ($imageContent === null) {
                return '下载图片失败，请检查URL是否正确。';
            }

            // 获取文件扩展名
            $extension = $this->getExtension($url, $imageContent);
            $filename = 'avatar_' . time() . '.' . $extension;

            // 上传到 Flarum
            $uploadedUrl = $this->uploadToFlarum($imageContent, $filename, 'avatar');
            if ($uploadedUrl === null) {
                return '上传图片到 Flarum 失败。';
            }

            // 更新用户头像
            $this->updateUser(['avatarUrl' => $uploadedUrl]);

            return "头像已更新！新头像URL：{$uploadedUrl}";
        } catch (\Exception $e) {
            return '设置头像失败：' . $e->getMessage();
        }
    }

    protected function setCover(string $url): string
    {
        if (empty($url)) {
            return '请提供背景图的URL。';
        }

        if (!preg_match('#^https?://#i', $url)) {
            return 'URL必须以 http:// 或 https:// 开头。';
        }

        try {
            // 下载图片
            $imageContent = $this->downloadImage($url);
            if ($imageContent === null) {
                return '下载图片失败，请检查URL是否正确。';
            }

            // 获取文件扩展名
            $extension = $this->getExtension($url, $imageContent);
            $filename = 'cover_' . time() . '.' . $extension;

            // 上传到 Flarum
            $uploadedUrl = $this->uploadToFlarum($imageContent, $filename, 'cover');
            if ($uploadedUrl === null) {
                return '上传图片到 Flarum 失败。';
            }

            // 更新用户背景图（通过 profile cover 扩展）
            $this->updateUser(['profileCoverUrl' => $uploadedUrl]);

            return "背景图已更新！新背景图URL：{$uploadedUrl}";
        } catch (\Exception $e) {
            return '设置背景图失败：' . $e->getMessage();
        }
    }

    protected function setBio(string $bio): string
    {
        if (empty($bio)) {
            return '请提供简介内容。';
        }

        try {
            $this->updateUser(['bio' => $bio]);
            return "简介已更新！新简介：{$bio}";
        } catch (\Exception $e) {
            return '设置简介失败：' . $e->getMessage();
        }
    }

    protected function clearAvatar(): string
    {
        try {
            $this->updateUser(['avatarUrl' => null]);
            return '头像已清除。';
        } catch (\Exception $e) {
            return '清除头像失败：' . $e->getMessage();
        }
    }

    protected function clearCover(): string
    {
        try {
            $this->updateUser(['profileCoverUrl' => null]);
            return '背景图已清除。';
        } catch (\Exception $e) {
            return '清除背景图失败：' . $e->getMessage();
        }
    }

    protected function downloadImage(string $url): ?string
    {
        try {
            $response = $this->client->get($url, ['timeout' => 15]);
            return (string) $response->getBody();
        } catch (\Exception $e) {
            error_log('[flarum-zai-bot] downloadImage failed: ' . $e->getMessage());
            return null;
        }
    }

    protected function getExtension(string $url, string $content): string
    {
        // 从 URL 获取扩展名
        $path = parse_url($url, PHP_URL_PATH);
        if ($path) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'])) {
                return $ext;
            }
        }

        // 从内容检测 MIME 类型
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($content);

        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            default => 'jpg',
        };
    }

    protected function uploadToFlarum(string $content, string $filename, string $type): ?string
    {
        $token = null;
        $tempFile = null;

        try {
            $tempFile = tempnam(sys_get_temp_dir(), 'zai_bot_');
            file_put_contents($tempFile, $content);

            // 临时生成机器人用户的 Access Token（用后即删，避免在数据库中积累凭据）。
            // 注意 AccessToken 基类禁止直接 generate()，必须使用子类。
            $token = SessionAccessToken::generate($this->botUserId);

            $response = $this->client->post(
                rtrim($this->apiUrl, '/') . '/fof/upload',
                [
                    'headers' => [
                        'Authorization' => 'Token ' . $token->token,
                    ],
                    'multipart' => [
                        [
                            'name' => 'files[]',
                            'contents' => fopen($tempFile, 'r'),
                            'filename' => $filename,
                        ],
                    ],
                ]
            );

            $body = json_decode((string) $response->getBody(), true);
            if (!empty($body['data'][0]['attributes']['url'])) {
                return $body['data'][0]['attributes']['url'];
            }

            return null;
        } catch (\Exception $e) {
            error_log('[flarum-zai-bot] uploadToFlarum failed: ' . $e->getMessage());
            return null;
        } finally {
            if ($token) {
                try {
                    $token->delete();
                } catch (\Exception $e) {
                }
            }
            if ($tempFile && is_file($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    protected function updateUser(array $attributes): void
    {
        $user = User::find($this->botUserId);
        if (!$user) {
            throw new \RuntimeException('Bot user not found');
        }

        foreach ($attributes as $key => $value) {
            $user->{$key} = $value;
        }

        $user->save();
    }
}
