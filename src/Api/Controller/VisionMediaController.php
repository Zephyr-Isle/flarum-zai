<?php

namespace Zephyrisle\FlarumZaiBot\Api\Controller;

use Flarum\Foundation\Paths;
use GuzzleHttp\Client;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Laminas\Diactoros\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 多模态媒体代理：把 fof/upload 的私有文件（图片、视频、音频）以免鉴权方式返回给调用方。
 *
 * fof/upload 的下载路由需要 fof-upload.download 权限，AI 模型的 API 服务器
 * （如 OpenAI、MiMo）无法登录论坛，直接访问会 403，导致多模态理解失败。本端点绕过权限检查，
 * 直接从存储读取文件字节流式返回，供模型的视觉/音频/视频 API 拉取。
 *
 * 安全说明：URL 中的 UUID 不可猜测（与 fof/upload 自身依赖的模糊性一致），且
 * 媒体本身已展示在帖子/私信内容中，本端点不额外暴露任何未公开的内容。仅处理
 * 图片/视频/音频类型并限制文件大小，防止被滥用为任意文件代理。
 */
class VisionMediaController implements RequestHandlerInterface
{
    /** 最大代理文件大小（字节）：按媒体类型分档 */
    private const MAX_IMAGE_BYTES = 20 * 1024 * 1024;   // 20 MB
    private const MAX_VIDEO_BYTES = 300 * 1024 * 1024;  // 300 MB
    private const MAX_AUDIO_BYTES = 100 * 1024 * 1024;  // 100 MB

    /** 允许代理的 MIME 前缀 */
    private const ALLOWED_PREFIXES = ['image/', 'video/', 'audio/'];

    public function __construct(
        protected Paths $paths,
        protected FilesystemFactory $filesystem,
        protected Client $client
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $uuid = strtolower((string) $request->getAttribute('uuid'));

        $file = $this->findFile($uuid);

        if (!$file || $file->hidden) {
            return $this->textResponse(404, 'Not Found');
        }

        $type = $file->type ?: '';

        // 仅允许图片、视频、音频类型
        if ($type !== '' && !$this->isAllowedType($type)) {
            return $this->textResponse(404, 'Not Found');
        }

        // 按类型限制文件大小
        if ((int) $file->size > $this->maxBytesForType($type)) {
            return $this->textResponse(404, 'Not Found');
        }

        $bytes = $this->readBytes($file);

        if ($bytes === null) {
            return $this->textResponse(404, 'Not Found');
        }

        return $this->textResponse(200, $bytes, [
            'Content-Type' => $type !== '' ? $type : 'application/octet-stream',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * 判断 MIME 类型是否允许代理（图片、视频、音频）。
     */
    protected function isAllowedType(string $type): bool
    {
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($type, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 按 MIME 类型返回最大允许的文件大小（字节）。
     */
    protected function maxBytesForType(string $type): int
    {
        if (str_starts_with($type, 'video/')) {
            return self::MAX_VIDEO_BYTES;
        }
        if (str_starts_with($type, 'audio/')) {
            return self::MAX_AUDIO_BYTES;
        }

        return self::MAX_IMAGE_BYTES;
    }

    /**
     * 按 UUID 查找 fof/upload 文件，兼容两种表结构：
     * - 1.x：uuid 列（id 为自增整数）；
     * - 0.x：id 列本身就是 UUID 字符串。
     */
    protected function findFile(string $uuid): ?\FoF\Upload\File
    {
        if (!class_exists(\FoF\Upload\File::class) || $uuid === '') {
            return null;
        }

        try {
            $hasUuidColumn = \FoF\Upload\File::query()
                ->getConnection()
                ->getSchemaBuilder()
                ->hasColumn('fof_upload_files', 'uuid');
        } catch (\Throwable $e) {
            $hasUuidColumn = false;
        }

        try {
            if ($hasUuidColumn) {
                return \FoF\Upload\File::where('uuid', $uuid)->first();
            }

            return \FoF\Upload\File::where('id', $uuid)->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 按来源读取文件字节（与 fof/upload 自身的下载逻辑一致）：
     * 1. 本地磁盘（public/assets/files）——默认本地适配器（0.x 与 1.x 通用）；
     * 2. private-shared 磁盘（1.x 的私有共享文件）；
     * 3. 外部适配器（如 S3）：服务端 GET $file->url 取回内容。
     */
    protected function readBytes(\FoF\Upload\File $file): ?string
    {
        // 1) 本地文件
        try {
            $localPath = $this->paths->public . '/assets/files/' . $file->path;
            if (is_file($localPath)) {
                $bytes = @file_get_contents($localPath);
                if ($bytes !== false && $bytes !== '') {
                    return $bytes;
                }
            }
        } catch (\Throwable $e) {
        }

        // 2) private-shared 磁盘
        try {
            $bytes = $this->filesystem->disk('private-shared')->get($file->path);
            if (is_string($bytes) && $bytes !== '') {
                return $bytes;
            }
        } catch (\Throwable $e) {
        }

        // 3) 外部存储：拉取配置的公开 URL
        if ($file->url) {
            try {
                $response = $this->client->get($file->url, ['timeout' => 20]);
                if ($response->getStatusCode() === 200) {
                    return (string) $response->getBody();
                }
            } catch (\Throwable $e) {
            }
        }

        return null;
    }

    /**
     * 构造带字符串响应体的响应。本版本 Laminas Response 的构造函数把字符串
     * body 当作文件路径打开，因此写入内置的 php://memory 流再返回。
     */
    protected function textResponse(int $status, string $body, array $headers = []): Response
    {
        $response = new Response('php://memory', $status, $headers);
        $response->getBody()->write($body);
        $response->getBody()->rewind();

        return $response;
    }
}
