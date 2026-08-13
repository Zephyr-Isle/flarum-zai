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
 * 图片代理：把 fof/upload 的私有图片以免鉴权方式返回给调用方。
 *
 * fof/upload 的下载路由需要 fof-upload.download 权限，AI 模型的 API 服务器
 * （如 OpenAI）无法登录论坛，直接访问会 403，导致识图失败。本端点绕过权限检查，
 * 直接从存储读取文件字节流式返回，供模型的视觉 API 拉取。
 *
 * 安全说明：URL 中的 UUID 不可猜测（与 fof/upload 自身依赖的模糊性一致），且
 * 图片本身已展示在帖子/私信内容中，本端点不额外暴露任何未公开的内容。仅处理
 * 图片类型并限制文件大小，防止被滥用为任意文件代理。
 */
class VisionImageController implements RequestHandlerInterface
{
    /** 最大代理文件大小（字节）：超出则拒绝，防止超大文件拖垮请求 */
    private const MAX_BYTES = 20 * 1024 * 1024;

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
        if ($type !== '' && !str_starts_with($type, 'image/')) {
            return $this->textResponse(404, 'Not Found');
        }

        if ((int) $file->size > self::MAX_BYTES) {
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
