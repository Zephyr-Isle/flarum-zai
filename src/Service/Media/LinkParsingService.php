<?php

namespace Zephyrisle\FlarumZaiBot\Service\Media;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\Repository;

/**
 * 链接解析：自动识别帖子/私信中的链接，抓取网页标题与正文摘要注入上下文，
 * 让 Bot 能看到链接内容。
 *
 * 安全与资源控制：
 *   - 内网拦截：localhost、字面私有/保留 IP 直接拒绝（SSRF 防护；不做 DNS 重绑定防护）
 *   - 域名黑名单：设置中每行一个域名，精确或子域匹配
 *   - 超时与下载上限：超时秒数、最大下载字节数可配置
 *   - 仅解析 text/html 与 text/plain
 *   - 结果按 URL 哈希缓存（24 小时），相同链接直接复用
 */
class LinkParsingService
{
    private const CACHE_TTL_SECONDS = 86400;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Client $client,
        protected Repository $cache
    ) {
    }

    /**
     * 解析内容中的链接，返回 [{url, title, summary}, ...]。
     */
    public function parse(string $contentHtml): array
    {
        $maxLinks = max(1, min(5, (int) $this->settings->get('flarum-zai-bot.media_link_max_links', 2)));
        $timeout = max(1, (int) $this->settings->get('flarum-zai-bot.media_link_timeout', 8));
        $maxBytes = max(1024, (int) $this->settings->get('flarum-zai-bot.media_link_max_bytes', 524288));

        $results = [];
        foreach ($this->extractUrls($contentHtml, $maxLinks) as $url) {
            $info = $this->fetchSummary($url, $timeout, $maxBytes);
            if ($info) {
                $results[] = $info;
            }
        }

        return $results;
    }

    /**
     * 提取内容中的 http(s) URL（去重、去尾部分隔符）。
     *
     * @return string[]
     */
    protected function extractUrls(string $html, int $max): array
    {
        if (!preg_match_all('#https?://[^\s<>"\']+#i', $html, $matches)) {
            return [];
        }

        $urls = [];
        foreach ($matches[0] as $url) {
            $url = rtrim(html_entity_decode(trim($url), ENT_QUOTES), '.,;:!?)]}>"\'');
            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) && !isset($urls[$url])) {
                $urls[$url] = $url;
                if (count($urls) >= $max) {
                    break;
                }
            }
        }

        return array_values($urls);
    }

    protected function fetchSummary(string $url, int $timeout, int $maxBytes): ?array
    {
        $key = 'zai-link:' . hash('sha256', $url);

        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        if ($this->isBlocked($url)) {
            return null;
        }

        try {
            $response = $this->client->get($url, [
                'timeout' => $timeout,
                'allow_redirects' => ['max' => 3],
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (compatible; ZaiBot/1.0; +https://github.com/Zephyr-Isle/flarum-zai-bot)',
                    'Accept' => 'text/html,text/plain;q=0.9,*/*;q=0.5',
                ],
                'stream' => true,
            ]);
        } catch (\Exception $e) {
            $this->cache->put($key, ['url' => $url, 'title' => '', 'summary' => ''], self::CACHE_TTL_SECONDS);

            return null;
        }

        $contentType = $response->getHeaderLine('Content-Type');
        if (!preg_match('#^(text/html|text/plain)#i', $contentType)) {
            return null;
        }

        // 流式读取，限制下载体积
        $chunk = $response->getBody()->read($maxBytes + 1);
        $truncated = strlen($chunk) > $maxBytes;
        $html = $truncated ? substr($chunk, 0, $maxBytes) : $chunk;

        $title = $this->extractTitle($html);
        $summary = $this->extractSummary($html);

        if ($truncated) {
            $summary = trim($summary) !== '' ? $summary . '…' : '(页面过大，仅截取开头)';
        }

        $info = ['url' => $url, 'title' => $title, 'summary' => $summary];
        $this->cache->put($key, $info, self::CACHE_TTL_SECONDS);

        return $info;
    }

    protected function extractTitle(string $html): string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            return trim(preg_replace('/\s+/u', ' ', strip_tags($m[1])));
        }

        return '';
    }

    protected function extractSummary(string $html): string
    {
        // meta description
        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)["\']/i', $html, $m)
            || preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']description["\']/i', $html, $m)) {
            $description = trim(strip_tags($m[1]));
            if ($description !== '') {
                return mb_substr($description, 0, 200);
            }
        }

        // 回退：取页面可见文本开头
        $text = strip_tags($html);
        $text = preg_replace('/\s+/u', ' ', $text);

        return mb_substr(trim($text), 0, 200);
    }

    /**
     * 内网/黑名单拦截。
     */
    protected function isBlocked(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return true;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        // 字面 IP：私有/保留地址直接拦截
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return true;
            }
        }

        foreach ($this->blacklist() as $domain) {
            if ($domain !== '' && ($host === $domain || str_ends_with($host, '.' . $domain))) {
                return true;
            }
        }

        return false;
    }

    /**
     * 域名黑名单（每行一个，可含子域匹配）。
     *
     * @return string[]
     */
    protected function blacklist(): array
    {
        $raw = (string) $this->settings->get('flarum-zai-bot.media_link_blacklist', '');
        $domains = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = strtolower(trim($line));
            if ($line !== '' && !str_starts_with($line, '#')) {
                $domains[] = $line;
            }
        }

        return $domains;
    }
}
