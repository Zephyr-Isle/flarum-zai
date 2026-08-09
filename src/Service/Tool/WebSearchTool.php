<?php

namespace Zephyrisle\FlarumZaiBot\Service\Tool;

use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;

class WebSearchTool implements ToolInterface
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Client $client,
        protected UrlGenerator $url
    ) {}

    public function getName(): string
    {
        return 'web_search';
    }

    public function getDescription(): string
    {
        return '搜索互联网或读取指定 URL 的内容。用于获取实时信息、新闻、文档等外部内容。使用 search 参数搜索，或使用 url 参数读取特定页面。';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => '搜索关键词（与 url 二选一）',
                ],
                'url' => [
                    'type' => 'string',
                    'description' => '要读取的完整 URL（与 query 二选一）',
                ],
                'max_results' => [
                    'type' => 'integer',
                    'description' => '最大返回结果数（默认5，仅搜索时有效）',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $args): string
    {
        $query = $args['query'] ?? '';
        $url = $args['url'] ?? '';
        $maxResults = min((int) ($args['max_results'] ?? 5), 10);

        if (empty($query) && empty($url)) {
            return '请提供搜索关键词 (query) 或要读取的 URL (url)。';
        }

        $enabled = (bool) $this->settings->get('flarum-zai-bot.jina_optimization_mode', false);
        if (!$enabled) {
            return 'Web search is disabled. Enable Jina optimization mode in settings to use this feature.';
        }

        if ($url) {
            // 只允许 http/https，避免模型被诱导读取 file:// 等本地协议
            if (!$this->isValidTargetUrl($url)) {
                return "无法读取该 URL：仅支持 http/https 协议的链接。";
            }

            return $this->readUrl($url);
        }

        return $this->searchWeb($query, $maxResults);
    }

    /**
     * 校验目标 URL：仅允许 http/https 且必须包含主机名。
     * 必须在 parse_url 之前检查原始字符串，因为 parse_url() 会改变畸形输入：
     * - 换行等控制字符会被剥离/改写（"https://exa\nmple.com" 解析后 host 变成 "exa_mple.com"）
     * - 反斜杠会被解析到 user 部分（"https://example.com\@evil.com" 的 host 变成 "evil.com"），
     *   合法的 http/https URL 中反斜杠必须百分号编码（%5C），出现即视为畸形输入
     * 原始串检查覆盖了所有空白/控制字符，因此解析后无需再检查 host。
     * 这是纵深防御——实际抓取由 Jina（或自定义代理）执行。
     */
    protected function isValidTargetUrl(string $url): bool
    {
        // 原始 URL 不允许空白/控制字符（正则）或反斜杠（str_contains，避免转义歧义）
        if (preg_match('/[\x00-\x20\x7f]/', $url) || str_contains($url, '\\')) {
            return false;
        }

        $parts = parse_url($url);

        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        return in_array(strtolower($parts['scheme']), ['http', 'https'], true);
    }

    protected function getSearchBaseUrl(): string
    {
        $proxy = $this->settings->get('flarum-zai-bot.jina_proxy_url', '');
        if ($proxy) {
            return rtrim($proxy, '/');
        }
        if ((bool) $this->settings->get('flarum-zai-bot.jina_use_builtin_proxy', false)) {
            return $this->url->to('api')->base() . '/zai-bot/jina-proxy?action=search&q=';
        }
        return 'https://s.jinaai.cn';
    }

    protected function getReaderBaseUrl(): string
    {
        $proxy = $this->settings->get('flarum-zai-bot.jina_proxy_url', '');
        if ($proxy) {
            return rtrim($proxy, '/');
        }
        if ((bool) $this->settings->get('flarum-zai-bot.jina_use_builtin_proxy', false)) {
            return $this->url->to('api')->base() . '/zai-bot/jina-proxy?action=read&url=';
        }
        return 'https://r.jinaai.cn';
    }

    protected function searchWeb(string $query, int $maxResults): string
    {
        try {
            $baseUrl = $this->getSearchBaseUrl();
            $url = str_contains($baseUrl, '?') ? $baseUrl . urlencode($query) : $baseUrl . '/' . urlencode($query);

            $response = $this->client->get($url, [
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'timeout' => 15,
            ]);

            $body = json_decode($response->getBody(), true);
            $data = $body['data'] ?? [];

            if (empty($data)) {
                return "未找到与「{$query}」相关的搜索结果。";
            }

            $output = "🌐 搜索「{$query}」的结果：\n";
            $count = 0;
            foreach ($data as $item) {
                if ($count >= $maxResults) break;
                $title = $item['title'] ?? '无标题';
                $snippet = $item['description'] ?? $item['content'] ?? '';
                $link = $item['url'] ?? '';
                $output .= "\n{$title}";
                if ($link) $output .= "\n  链接：{$link}";
                if ($snippet) $output .= "\n  摘要：" . mb_substr(strip_tags($snippet), 0, 200);
                $output .= "\n";
                $count++;
            }

            return trim($output);
        } catch (\Exception $e) {
            return "搜索失败：{$e->getMessage()}";
        }
    }

    protected function readUrl(string $url): string
    {
        try {
            $baseUrl = $this->getReaderBaseUrl();
            // 使用内建代理时 URL 作为 query 参数传递，必须编码（否则 & 等字符会破坏参数）
            $targetUrl = str_contains($baseUrl, '?') ? $baseUrl . rawurlencode($url) : $baseUrl . '/' . $url;

            $response = $this->client->get($targetUrl, [
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Return-Format' => 'markdown',
                ],
                'timeout' => 20,
            ]);

            $body = json_decode($response->getBody(), true);
            $content = $body['data']['content'] ?? $body['content'] ?? '';

            if (empty($content)) {
                return "无法读取 URL：{$url}";
            }

            $content = strip_tags($content);
            $content = mb_substr($content, 0, 3000);

            return "📄 {$url} 的内容：\n\n{$content}";
        } catch (\Exception $e) {
            return "读取 URL 失败：{$e->getMessage()}";
        }
    }
}
