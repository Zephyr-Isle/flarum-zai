<?php

namespace Zephyrisle\FlarumZaiBot\Service;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Zephyrisle\FlarumZaiBot\Service\Tool\ToolInterface;

class AIService
{
    protected array $personalities = [
        'friendly' => '你是一个友好热情的社区论坛助手。你乐于助人、耐心细致，回复自然温暖，偶尔使用表情符号让对话更亲切。你总是用中文回复。',
        'tsundere' => '你是一个傲娇的论坛助手。表面上你说话带刺、显得不耐烦，但实际上你很关心用户。你的语气要表现出"哼"、"才不是"、"笨蛋"等傲娇特征。即使说话不好听，最终还是会给用户提供有用的帮助。你用中文回复。',
        'loli' => '你是一个可爱的萝莉风格的论坛助手。你说话活泼可爱，带有很多语气词如"啦"、"呀"、"呢"，自称"人家"。你对一切充满好奇，回复欢乐活泼。你用中文回复。',
        'cool' => '你是一个高冷寡言的论坛助手。你说话简洁直接，不喜欢废话，只说重点。你觉得用户问的问题太简单时会不耐烦，但专业能力很强。你用中文回复，能用三个字说完绝不用五个字。',
        'custom' => null,
    ];

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Client $client
    ) {}

    public function generateReply(string $prompt, array $context = [], array $tools = []): ?string
    {
        $apiUrl = $this->settings->get('flarum-zai-bot.api_url', 'https://api.openai.com/v1');
        $apiKey = $this->settings->get('flarum-zai-bot.api_key');
        $model = $this->settings->get('flarum-zai-bot.model', 'gpt-3.5-turbo');

        if (!$apiKey) {
            return null;
        }

        $messages = [
            ['role' => 'system', 'content' => $this->buildSystemPrompt()],
        ];

        if (!empty($context['current_post_id'])) {
            $messages[] = ['role' => 'system', 'content' => "当前帖子ID：{$context['current_post_id']}"];
        }

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
            if (!empty($context['bio'])) {
                $userContext .= "- 个人简介：{$context['bio']}\n";
            }
            if (!empty($context['birthday'])) {
                $userContext .= "- 生日：{$context['birthday']}\n";
            }
            $messages[] = ['role' => 'system', 'content' => trim($userContext)];
        }

        if (!empty($context['conversation_history']) && is_array($context['conversation_history'])) {
            $historyStr = "对话历史：\n";
            foreach ($context['conversation_history'] as $entry) {
                $postId = $entry['post_id'] ?? '';
                $author = $entry['author'] ?? '未知';
                $content = $entry['content'] ?? '';
                $historyStr .= "- [post {$postId}] {$author}：{$content}\n";
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
            $messages[] = ['role' => 'system', 'content' => '你可以使用以下工具：get_user_info（查询用户完整资料）、view_user_files（查看用户上传的文件）、search_forum（搜索论坛内容）、get_stickers（查看贴纸）、get_post_likes（查看点赞信息，或使用action:like/unlike进行点赞/取消点赞）。当用户询问详细信息时主动调用对应工具。如果用户要求点赞或取消点赞，使用get_post_likes工具并设置action参数。'];
        }

        try {
            $requestBody = [
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => 1500,
                'temperature' => 0.8,
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
                $messages[] = $message;
                return $this->handleToolCalls($message['tool_calls'], $messages, $tools, $apiUrl, $apiKey, $model);
            }

            return $message['content'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function buildSystemPrompt(): string
    {
        $personality = $this->settings->get('flarum-zai-bot.personality', 'friendly');

        if ($personality === 'custom') {
            return $this->settings->get('flarum-zai-bot.system_prompt', 'You are a friendly community forum assistant. Keep responses concise and helpful.');
        }

        $prompt = $this->personalities[$personality] ?? $this->personalities['friendly'];

        $prompt .= "\n\n你是一个论坛AI助手，名称是" . $this->settings->get('flarum-zai-bot.bot_display_name', 'Yuki') . "。";

        return $prompt;
    }

    protected function handleToolCalls(array $toolCalls, array $messages, array $tools, string $apiUrl, string $apiKey, string $model): ?string
    {
        $toolMap = [];
        foreach ($tools as $tool) {
            $toolMap[$tool->getName()] = $tool;
        }

        $toolDefinitions = [];
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
            $requestBody = [
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => 1500,
                'temperature' => 0.8,
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
                $messages[] = $message;
                return $this->handleToolCalls($message['tool_calls'], $messages, $tools, $apiUrl, $apiKey, $model);
            }

            return $message['content'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
