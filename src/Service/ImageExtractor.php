<?php

namespace Zephyrisle\FlarumZaiBot\Service;

use Illuminate\Container\Container;

/**
 * 从 Flarum 帖子/私信内容（已解析的 HTML）中提取图片 URL，供多模态模型识图使用。
 *
 * fof/upload 的下载路由（/api/fof/download/{uuid} 或 /assets/files/{uuid}/...）受
 * fof-upload.download 权限保护，AI 模型的 API 服务器无法登录论坛、直接访问会 403。
 * 因此检测到 fof/upload 图片时，会提取文件 UUID 并改写为本扩展自带的免鉴权图片代理
 * 路由（VisionImageController），保证模型能真正拉到图片内容。
 */
final class ImageExtractor
{
    /**
     * fof/upload 下载 URL 的两种形态（1.x：/api/fof/download/{uuid}，0.x：/assets/files/{uuid}/{name}）。
     * UUID 后要求跟分隔符（/、?、#、&、空白或字符串结束），避免误吞更长字符串中的片段。
     */
    private const UPLOAD_URL_PATTERN = '#(?:fof/download|assets/files)/([0-9a-fA-F-]{36})(?:[/?&\s]|$)#';

    /**
     * 提取内容中的图片 URL（http(s) 或 data:image/）。
     *
     * 会过滤 Flarum 内置 emoji 的 CDN（cdn.jsdelivr.net / twemoji），
     * 避免把表情图标当作需要识别的图片发送给模型，浪费 token 与额度。
     *
     * @param string|null $baseUrl 论坛根地址（用于生成代理 URL），留空时尝试从容器解析
     */
    public static function fromHtml(string $html, int $max = 4, ?string $baseUrl = null): array
    {
        if ($html === '') {
            return [];
        }

        $urls = [];

        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $url) {
                $url = html_entity_decode(trim($url), ENT_QUOTES);

                if ($url === '') {
                    continue;
                }

                // 跳过 Flarum 内置 emoji（twemoji CDN 上的 72x72 小图标）
                if (stripos($url, 'cdn.jsdelivr.net') !== false) {
                    continue;
                }

                $resolved = self::resolveUrl($url, $baseUrl);
                if ($resolved === null) {
                    continue;
                }

                $urls[] = $resolved;
            }
        }

        return array_slice(array_values(array_unique($urls)), 0, max(0, $max));
    }

    /**
     * 把单个 img src 解析为模型可直接访问的图片 URL：
     *
     * - fof/upload 下载 URL → 提取 UUID，改写为免鉴权代理路由
     *   {base}/api/zai-bot/vision-image/{uuid}；
     * - 普通 http(s) / data:image/ → 原样返回；
     * - 其余（相对路径、无法确定论坛根地址时的 fof/upload URL）→ null，跳过。
     */
    protected static function resolveUrl(string $url, ?string $baseUrl = null): ?string
    {
        if (preg_match(self::UPLOAD_URL_PATTERN, $url, $m) && class_exists(\FoF\Upload\File::class)) {
            $base = $baseUrl ?? self::forumUrl();

            if ($base === null) {
                return null;
            }

            return rtrim($base, '/') . '/api/zai-bot/vision-image/' . strtolower($m[1]);
        }

        if (preg_match('#^https?://#i', $url) || preg_match('#^data:image/#i', $url)) {
            return $url;
        }

        return null;
    }

    /**
     * 提取内容中所有 fof/upload 文件的 UUID（去重）。
     *
     * @return string[]
     */
public static function uploadUuids(string $html): array
    {
        if ($html === '') {
            return [];
        }

        $uuids = [];
        // 先按属性提取 URL（img src / a href），再逐条匹配 UUID，避免属性引号干扰定界符
        if (preg_match_all('/(?:src|href)=["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $url) {
                if (preg_match(self::UPLOAD_URL_PATTERN, html_entity_decode($url, ENT_QUOTES), $m)) {
                    $uuids[] = strtolower($m[1]);
                }
            }
        }

        return array_values(array_unique($uuids));
    }

    /**
     * 图片分类，帮助模型区分普通图片与表情包/动图/贴纸：
     * 返回 image | gif | emoji | sticker。
     */
    public static function classify(string $url): string
    {
        $url = strtolower($url);
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        if (str_ends_with($path, '.gif') || str_contains($url, 'content-type=gif')) {
            return 'gif';
        }
        if (str_contains($url, 'emoji') || str_contains($url, 'twemoji')) {
            return 'emoji';
        }
        if (str_contains($url, 'sticker') || str_contains($url, '贴纸')) {
            return 'sticker';
        }

        return 'image';
    }

    /**
     * 从 Flarum 容器解析论坛根地址（如 https://forum.example）。队列任务运行在
     * Flarum 容器内，可直接解析；容器不可用时返回 null。
     */
    protected static function forumUrl(): ?string
    {
        $container = Container::getInstance();

        if (!$container || !$container->bound('flarum.config')) {
            return null;
        }

        try {
            $url = $container->make('flarum.config')->url();

            return is_string($url) && $url !== '' ? $url : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
