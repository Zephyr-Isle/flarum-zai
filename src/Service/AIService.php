<?php

namespace Zephyrisle\FlarumZaiBot\Service;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Zephyrisle\FlarumZaiBot\Service\Tool\ToolInterface;

class AIService
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Client $client
    ) {}

    public function generateReply(string $prompt, array $context = [], array $tools = []): ?string
    {
        $apiUrl = $this->settings->get('flarum-zai-bot.api_url', 'https://api.openai.com/v1');
        $apiKey = $this->settings->get('flarum-zai-bot.api_key');
        $model = $this->settings->get('flarum-zai-bot.model', 'gpt-3.5-turbo');
        $systemPrompt = $this->settings->get('flarum-zai-bot.system_prompt', 'You are a friendly community forum assistant. Keep responses concise and helpful.');

        if (!$apiKey) {
            return null;
        }

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'system', 'content' => '请使用中文回复。'],
        ];

        if (!empty($context['discussion_title'])) {
            $messages[] = ['role' => 'system', 'content' => "当前讨论主题：{$context['discussion_title']}"];
        }

        if (!empty($context['username'])) {
            $userContext = "当前发帖用户信息：\n";
            $userContext .= "- 用户名：{$context['username']}\n";
            if (!empty($context['display_name'])) {
                $userContext .= "- 昵称：{$context['display_name']}\n";
            }
            if (isset($context['is_verified'])) {
                $userContext .= "- 认证状态：" . ($context['is_verified'] ? '已认证' : '未认证') . "\n";
            }
            if (!empty($context['verified_tier'])) {
                $userContext .= "- 认证等级：{$context['verified_tier']}\n";
            }
            if (!empty($context['verified_at'])) {
                $userContext .= "- 认证时间：{$context['verified_at']}\n";
            }
            if (!empty($context['group_names'])) {
                $userContext .= "- 用户组：{$context['group_names']}\n";
            }
            if (!empty($context['post_count'])) {
                $userContext .= "- 发帖数：{$context['post_count']}\n";
            }
            if (!empty($context['joined_at'])) {
                $userContext .= "- 注册时间：{$context['joined_at']}\n";
            }
            $messages[] = ['role' => 'system', 'content' => trim($userContext)];
        }

        if (!empty($context['conversation_history']) && is_array($context['conversation_history'])) {
            $historyStr = "对话历史：\n";
            foreach ($context['conversation_history'] as $entry) {
                $author = $entry['author'] ?? '未知';
                $content = $entry['content'] ?? '';
                $historyStr .= "- {$author}：{$content}\n";
            }
            $messages[] = ['role' => 'system', 'content' => trim($historyStr)];
        }

        $messages[] = ['role' => 'user', 'content' => $prompt];

        $toolDefinitions = [];
        if (!empty($tools)) {
            foreach ($tools as $tool) {
                $toolDefinitions[] = [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool->getName(),
                        'description' => $tool->getDescription(),
                        'parameters' => $tool->getParameters(),
                    ],
                ];
            }
            $messages[] = ['role' => 'system', 'content' => '你拥有工具可以使用。当用户询问详细信息（如用户资料、搜索结果）时，请主动调用对应工具获取最新数据，不要仅凭已提供的信息做有限回复。'];
        }

        try {
            $requestBody = [
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => 1000,
                'temperature' => 0.7,
            ];

            if (!empty($toolDefinitions)) {
                $requestBody['tools'] = $toolDefinitions;
                $requestBody['tool_choice'] = 'auto';
            }

            $response = $this->client->post(rtrim($apiUrl, '/') . '/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $requestBody,
                'timeout' => 60,
            ]);

            $body = json_decode($response->getBody(), true);
            $choice = $body['choices'][0] ?? null;

            if (!$choice) {
                return null;
            }

            $message = $choice['message'] ?? [];

            if (!empty($message['tool_calls'])) {
                return $this->handleToolCalls($message['tool_calls'], $messages, $tools, $apiUrl, $apiKey, $model);
            }

            return $message['content'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function handleToolCalls(array $toolCalls, array $messages, array $tools, string $apiUrl, string $apiKey, string $model): ?string
    {
        $toolMap = [];
        foreach ($tools as $tool) {
            $toolMap[$tool->getName()] = $tool;
        }

        foreach ($toolCalls as $tc) {
            $functionName = $tc['function']['name'] ?? '';
            $arguments = json_decode($tc['function']['arguments'] ?? '{}', true);

            $result = '工具调用失败';
            if (isset($toolMap[$functionName])) {
                try {
                    $result = $toolMap[$functionName]->execute($arguments ?: []);
                } catch (\Exception $e) {
                    $result = '工具执行出错：' . $e->getMessage();
                }
            }

            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $tc['id'],
                'content' => $result,
            ];
        }

        try {
            $response = $this->client->post(rtrim($apiUrl, '/') . '/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'messages' => $messages,
                    'max_tokens' => 1000,
                    'temperature' => 0.7,
                ],
                'timeout' => 60,
            ]);

            $body = json_decode($response->getBody(), true);
            return $body['choices'][0]['message']['content'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
