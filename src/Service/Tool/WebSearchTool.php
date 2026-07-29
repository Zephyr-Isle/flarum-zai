<?php

namespace Zephyrisle\FlarumZaiBot\Service\Tool;

use GuzzleHttp\Client;

class WebSearchTool implements ToolInterface
{
    protected Client $http;

    public function __construct()
    {
        $this->http = new Client(['timeout' => 15]);
    }

    public function getName(): string
    {
        return 'search_web';
    }

    public function getDescription(): string
    {
        return '搜索互联网获取最新信息。当用户问到实时新闻、当前事件或你不确定的知识时，使用此工具获取最新资料。';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => '搜索关键词',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => '返回结果数量（默认5）',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $args): string
    {
        $query = $args['query'] ?? '';
        $limit = min((int) ($args['limit'] ?? 5), 10);

        if (empty($query)) {
            return '请提供搜索关键词。';
        }

        try {
            $response = $this->http->get('https://html.duckduckgo.com/html/', [
                'query' => ['q' => $query],
            ]);

            $html = $response->getBody()->getContents();

            preg_match_all('/<a[^>]*class="result__a"[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/s', $html, $links);
            preg_match_all('/<a[^>]*class="result__snippet"[^>]*>(.*?)<\/a>/s', $html, $snippets);

            $results = [];
            $count = min(count($links[0]), $limit);

            for ($i = 0; $i < $count; $i++) {
                $title = strip_tags($links[2][$i] ?? '');
                $url = $links[1][$i] ?? '';
                $snippet = strip_tags($snippets[1][$i] ?? '');
                $results[] = ($i + 1) . ". {$title}\n   {$url}\n   {$snippet}";
            }

            if (empty($results)) {
                return "未找到与「{$query}」相关的网络结果。";
            }

            return "网络搜索结果「{$query}」：\n" . implode("\n\n", $results);
        } catch (\Exception $e) {
            return "网络搜索失败：{$e->getMessage()}";
        }
    }
}
