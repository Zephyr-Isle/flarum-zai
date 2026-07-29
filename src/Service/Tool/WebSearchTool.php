<?php

namespace Zephyrisle\FlarumZaiBot\Service\Tool;

use GuzzleHttp\Client;

class WebSearchTool implements ToolInterface
{
    protected Client $http;

    public function __construct()
    {
        $this->http = new Client(['timeout' => 10]);
    }

    public function getName(): string
    {
        return 'web_search';
    }

    public function getDescription(): string
    {
        return '联网搜索互联网信息。可以搜索指定关键词，返回网页标题和摘要。适用于获取实时信息、新闻、资料查询等。';
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
                'count' => [
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
        $count = min((int) ($args['count'] ?? 5), 10);

        if (empty($query)) return '请提供搜索关键词。';

        try {
            $response = $this->http->get('https://api.duckduckgo.com/', [
                'query' => [
                    'q' => $query,
                    'format' => 'json',
                    'no_html' => 1,
                    'skip_disambig' => 1,
                ],
            ]);

            $body = json_decode($response->getBody(), true);
            $results = [];

            if (!empty($body['AbstractText'])) {
                $results[] = "[摘要] {$body['AbstractText']}";
                if (!empty($body['AbstractURL'])) {
                    $results[] = "  来源：{$body['AbstractURL']}";
                }
            }

            if (!empty($body['RelatedTopics'])) {
                foreach (array_slice($body['RelatedTopics'], 0, $count) as $topic) {
                    if (isset($topic['Text'])) {
                        $results[] = "- {$topic['Text']}";
                    }
                    if (isset($topic['FirstURL'])) {
                        $results[] = "  {$topic['FirstURL']}";
                    }
                }
            }

            if (empty($results)) {
                $results[] = "未找到关于「{$query}」的即时结果。";
                $results[] = "建议使用更精确的关键词重新搜索。";
            }

            $output = "搜索「{$query}」的结果：\n" . implode("\n", $results);
            return trim($output);

        } catch (\Exception $e) {
            try {
                $response = $this->http->get('https://html.duckduckgo.com/html/', [
                    'query' => ['q' => $query],
                ]);
                $html = (string) $response->getBody();

                preg_match_all('/<a[^>]+class="result__a"[^>]*href="([^"]*)"[^>]*>([\s\S]*?)<\/a>/', $html, $matches, PREG_SET_ORDER);

                $results = [];
                foreach (array_slice($matches, 0, $count) as $m) {
                    $title = strip_tags($m[2] ?? '');
                    $url = html_entity_decode($m[1] ?? '');
                    if ($title) {
                        $results[] = "- {$title}\n  {$url}";
                    }
                }

                if (empty($results)) {
                    return "搜索「{$query}」未找到结果。";
                }

                return "搜索「{$query}」的结果：\n" . implode("\n", $results);
            } catch (\Exception $e2) {
                return "联网搜索暂时不可用：{$e2->getMessage()}";
            }
        }
    }
}
