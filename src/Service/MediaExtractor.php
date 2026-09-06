<?php

namespace Zephyrisle\FlarumZaiBot\Service;

use Illuminate\Container\Container;

/**
 * 从 Flarum 帖子/私信内容（已解析的 HTML）中提取多模态媒体 URL（图片、视频、音频），
 * 供多模态模型理解使用。
 *
 * fof/upload 的下载路由（/api/fof/download/{uuid} 或 /assets/files/{uuid}/...）受
 * fof-upload.download 权限保护，AI 模型的 API 服务器无法登录论坛、直接访问会 403。
 * 因此检测到 fof/upload 媒体时，会提取文件 UUID 并改写为本扩展自带的免鉴权媒体代理
 * 路由（VisionMediaController），保证模型能真正拉到媒体内容。
 */
class MediaExtractor
{
    /** 图片 URL → 已确认的表情包名称 的静态缓存，避免对同一 URL 反复查库 */
    private static array $emojiNameCache = [];

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
     * 提取内容中的视频 URL（<video src>、<video><source src>、data:video/）。
     *
     * <source> 标签的 src 仅在属于 <video> 元素时才算作视频；属于 <audio> 的会被
     * audiosFromHtml 处理，避免跨类型串味。
     *
     * @param string|null $baseUrl 论坛根地址（用于生成代理 URL），留空时尝试从容器解析
     */
    public static function videosFromHtml(string $html, int $max = 2, ?string $baseUrl = null): array
    {
        if ($html === '') {
            return [];
        }

        $urls = [];

        // <video src="...">
        if (preg_match_all('/<video[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $url) {
                $url = html_entity_decode(trim($url), ENT_QUOTES);
                if ($url !== '') {
                    $resolved = self::resolveUrl($url, $baseUrl, 'video');
                    if ($resolved !== null) {
                        $urls[] = $resolved;
                    }
                }
            }
        }

        // <video ...><source src="...">：仅取属于 <video> 的 <source>（<audio> 内的由 audiosFromHtml 处理）
        if (preg_match_all('#<video\b[^>]*>(.*?)</video>#is', $html, $videoBlocks)) {
            foreach ($videoBlocks[1] as $inner) {
                if (preg_match_all('/<s(?:ource)[^>]+src=["\']([^"\']+)["\']/i', $inner, $matches)) {
                    foreach ($matches[1] as $url) {
                        $url = html_entity_decode(trim($url), ENT_QUOTES);
                        if ($url !== '') {
                            $resolved = self::resolveUrl($url, $baseUrl, 'video');
                            if ($resolved !== null) {
                                $urls[] = $resolved;
                            }
                        }
                    }
                }
            }
        }

        // data:video/ inline
        if (preg_match_all('/data:video\/[^"\'>\s]+/i', $html, $matches)) {
            foreach ($matches[0] as $url) {
                $urls[] = $url;
            }
        }

        return array_slice(array_values(array_unique($urls)), 0, max(0, $max));
    }

    /**
     * 提取内容中的音频 URL（<audio src>、<audio><source src>、data:audio/）。
     *
     * <source> 标签的 src 仅在属于 <audio> 元素时才算作音频；属于 <video> 的会被
     * videosFromHtml 处理，避免跨类型串味。
     *
     * @param string|null $baseUrl 论坛根地址（用于生成代理 URL），留空时尝试从容器解析
     */
    public static function audiosFromHtml(string $html, int $max = 2, ?string $baseUrl = null): array
    {
        if ($html === '') {
            return [];
        }

        $urls = [];

        // <audio src="...">
        if (preg_match_all('/<audio[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $url) {
                $url = html_entity_decode(trim($url), ENT_QUOTES);
                if ($url !== '') {
                    $resolved = self::resolveUrl($url, $baseUrl, 'audio');
                    if ($resolved !== null) {
                        $urls[] = $resolved;
                    }
                }
            }
        }

        // <audio ...><source src="...">：仅取属于 <audio> 的 <source>，忽略 <video> 内的
        if (preg_match_all('#<audio\b[^>]*>(.*?)</audio>#is', $html, $audioBlocks)) {
            foreach ($audioBlocks[1] as $inner) {
                if (preg_match_all('/<s(?:ource)[^>]+src=["\']([^"\']+)["\']/i', $inner, $matches)) {
                    foreach ($matches[1] as $url) {
                        $url = html_entity_decode(trim($url), ENT_QUOTES);
                        if ($url === '') {
                            continue;
                        }
                        $resolved = self::resolveUrl($url, $baseUrl, 'audio');
                        if ($resolved !== null && self::classifyMedia($resolved) === 'audio') {
                            $urls[] = $resolved;
                        }
                    }
                }
            }
        }

        // data:audio/ inline
        if (preg_match_all('/data:audio\/[^"\'>\s]+/i', $html, $matches)) {
            foreach ($matches[0] as $url) {
                $urls[] = $url;
            }
        }

        return array_slice(array_values(array_unique($urls)), 0, max(0, $max));
    }

    /**
     * 把单个媒体 src 解析为模型可直接访问的 URL：
     *
     * - fof/upload 下载 URL → 提取 UUID，改写为免鉴权代理路由
     *   {base}/api/zai-bot/vision-media/{uuid}；
     * - 普通 http(s) / data: 前缀 → 原样返回；
     * - 其余（相对路径、无法确定论坛根地址时的 fof/upload URL）→ null，跳过。
     *
     * @param string $type 媒体类型：'image' | 'video' | 'audio'，用于限制 data: 前缀
     */
    protected static function resolveUrl(string $url, ?string $baseUrl = null, string $type = 'image'): ?string
    {
        if (preg_match(self::UPLOAD_URL_PATTERN, $url, $m) && class_exists(\FoF\Upload\File::class)) {
            $base = $baseUrl ?? self::forumUrl();

            if ($base === null) {
                return null;
            }

            return rtrim($base, '/') . '/api/zai-bot/vision-media/' . strtolower($m[1]);
        }

        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $dataPattern = match ($type) {
            'video' => '#^data:video/#i',
            'audio' => '#^data:audio/#i',
            default => '#^data:image/#i',
        };

        if (preg_match($dataPattern, $url)) {
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
        // 先按属性提取 URL（img src / a href / video src / source src / audio src），再逐条匹配 UUID，避免属性引号干扰定界符
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
     *
     * cloudnest/user-emoji 扩展的表情包（图片 URL 通常是 fof/upload，进入本方法前已被
     * resolveUrl 改写为 /api/zai-bot/vision-media/{uuid} 代理形式）会通过数据库反查识别为
     * 表情包（sticker）；未安装该扩展时静默跳过。
     */
    public static function classify(string $url): string
    {
        $url = strtolower($url);
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        // cloudnest 表情包：非本机 fof/upload 图片不让 AI 识别，直接反查数据库
        if (self::lookupEmojiName($url) !== null) {
            return 'sticker';
        }

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
     * 反查 cloudnest/user-emoji 表情包名称（未安装该扩展时返回 null）。
     *
     * 入参通常是 resolveUrl 改写后的代理 URL（/api/zai-bot/vision-media/{uuid}），
     * 因此先提取 UUID 按 LIKE 匹配 emojis.emoji_url，再兜底做整串等值匹配。
     * 结果按 URL 静态缓存，避免多次查询。
     */
    public static function lookupEmojiName(string $url): ?string
    {
        if (!class_exists(\CloudNest\Emoji\Emoji::class)) {
            return null;
        }

        if (array_key_exists($url, self::$emojiNameCache)) {
            return self::$emojiNameCache[$url];
        }

        $name = null;
        try {
            $uuid = self::extractUuid($url);
            $query = \CloudNest\Emoji\Emoji::query();
            if ($uuid !== null) {
                $query = $query->where('emoji_url', 'LIKE', '%' . $uuid . '%');
            }
            $query = $query->orWhere('emoji_url', $url);

            $emoji = $query->first();
            if ($emoji && !empty($emoji->emoji_name)) {
                $name = (string) $emoji->emoji_name;
            }
        } catch (\Exception $e) {
            $name = null;
        }

        self::$emojiNameCache[$url] = $name;

        return $name;
    }

    /**
     * 从媒体 URL 中提取 36 位 UUID（本扩展代理 URL 或 fof/upload 两类形态）。
     */
    protected static function extractUuid(string $url): ?string
    {
        if (preg_match('#/vision-media/([0-9a-fA-F-]{36})#i', $url, $m)) {
            return strtolower($m[1]);
        }
        if (preg_match(self::UPLOAD_URL_PATTERN, $url, $m)) {
            return strtolower($m[1]);
        }

        return null;
    }

    /**
     * 媒体类型分类：根据 URL 判断是图片、视频还是音频。
     * 返回 image | video | audio。
     */
    public static function classifyMedia(string $url): string
    {
        $url = strtolower($url);
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        // 视频扩展名
        $videoExts = ['.mp4', '.mov', '.avi', '.wmv', '.webm', '.mkv', '.flv', '.m4v'];
        foreach ($videoExts as $ext) {
            if (str_ends_with($path, $ext)) {
                return 'video';
            }
        }
        if (str_contains($url, 'data:video/')) {
            return 'video';
        }

        // 音频扩展名
        $audioExts = ['.mp3', '.wav', '.flac', '.m4a', '.ogg', '.aac', '.wma', '.opus'];
        foreach ($audioExts as $ext) {
            if (str_ends_with($path, $ext)) {
                return 'audio';
            }
        }
        if (str_contains($url, 'data:audio/')) {
            return 'audio';
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
