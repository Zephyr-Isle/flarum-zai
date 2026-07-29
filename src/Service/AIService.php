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
        $model = $this->settings->get('flarum-zai-bot.model', 'gpt-3.5-turbo');

        $channel = $context['channel'] ?? 'forum';
        $affinityScore = $context['affinity_score'] ?? null;
        $userId = $context['user_id'] ?? null;

        $dailyInfo = $this->buildDailyInfo($channel, $affinityScore);

        $messages = [
            ['role' => 'system', 'content' => $this->buildSystemPrompt()],
            ['role' => 'system', 'content' => $dailyInfo],
        ];

        if ($userId && !empty($context['portrait_summary'])) {
            $messages[] = ['role' => 'system', 'content' => "用户画像：{$context['portrait_summary']}"];
        }

        if ($userId && !empty($context['memories']) && is_array($context['memories'])) {
            $memStr = "相关记忆：\n";
            foreach ($context['memories'] as $mem) {
                $time = $mem['created_at'] ?? '';
                $content = $mem['content'] ?? '';
                $memStr .= "- [{$time}] {$content}\n";
            }
            $messages[] = ['role' => 'system', 'content' => trim($memStr)];
        }

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
            $messages[] = ['role' => 'system', 'content' => '你可以使用以下工具：get_user_info（查询用户完整资料）、view_user_files（查看用户上传的文件）、search_forum（搜索论坛内容）、get_stickers（查看可用贴纸）、send_sticker（发送贴纸到当前讨论）、get_post_likes（查看点赞信息，或使用action:like/unlike进行点赞/取消点赞）、update_user_portrait（更新用户画像和好感度）。根据对话场景自主决定调用合适的工具提供帮助。每次对话结束时调用update_user_portrait记录对用户的观察并调整好感度。'];
        }

        $keys = $this->getApiKeys();
        $lastError = null;

        foreach ($keys as $apiKey) {
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
                    continue;
                }

                $message = $choice['message'] ?? [];

                if (!empty($message['tool_calls'])) {
                    $messages[] = $message;
                    return $this->handleToolCalls($message['tool_calls'], $messages, $tools, $apiUrl, $keys, $model);
                }

                return $message['content'] ?? null;
            } catch (\Exception $e) {
                $lastError = $e;
                continue;
            }
        }

        return null;
    }

    protected function getApiKeys(): array
    {
        $raw = $this->settings->get('flarum-zai-bot.api_keys', '');
        $keys = array_filter(array_map('trim', explode(',', $raw)));

        if (empty($keys)) {
            $single = $this->settings->get('flarum-zai-bot.api_key', '');
            if ($single) {
                $keys = [$single];
            }
        }

        return $keys ?: [];
    }

    protected function postChat(array $messages, array $toolDefinitions, string $apiUrl, array $keys, string $model): ?array
    {
        foreach ($keys as $apiKey) {
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

                if ($choice) {
                    return $choice;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return null;
    }

    protected function buildDailyInfo(string $channel = 'forum', ?int $affinityScore = null): string
    {
        try {
            $timezone = $this->settings->get('flarum-zai-bot.timezone', 'Asia/Shanghai');
            $now = \Carbon\Carbon::now($timezone);
        } catch (\Exception $e) {
            $now = \Carbon\Carbon::now('Asia/Shanghai');
        }

        $weekdayNames = ['日', '一', '二', '三', '四', '五', '六'];
        $weekday = $weekdayNames[(int) $now->format('w')];
        $dateStr = $now->format('Y年m月d日');
        $timeStr = $now->format('H:i');

        $parts = [];
        $parts[] = "当前时间：{$dateStr} 星期{$weekday} {$timeStr}";

        $this->appendHolidayInfo($now, $parts);

        $weather = $this->getWeatherInfo();
        if ($weather) {
            $parts[] = $weather;
        }

        if ($channel === 'message') {
            $parts[] = '对话场景：当前是通过私信聊天与你交流，请以亲切的一对一对话方式回复，语气可以更随意亲密。';
        } else {
            $parts[] = '对话场景：当前是在论坛帖子中回复，请保持适当的公开场合语气，回复内容对其他浏览者也有参考价值。';
        }

        $hour = (int) $now->format('H');
        if ($channel === 'message' && ($hour >= 23 || $hour < 6)) {
            $parts[] = "提示：现在时间已晚（{$timeStr}），如果用户在聊天，可以在合适的时候关心一下让用户早点休息，但不要强行结束对话。";
        }

        if ($affinityScore !== null) {
            $level = $this->getAffinityLevel($affinityScore);
            $parts[] = "用户好感度：{$affinityScore}分（{$level}），好感度越高表示与用户关系越好，回复可以更热情亲切。每次互动结束后请使用update_user_portrait工具根据用户表现调整好感度。";
        }

        return implode("\n\n", $parts);
    }

    protected function appendHolidayInfo(\Carbon\Carbon $now, array &$parts): void
    {
        if (!class_exists(\ChineseHolidays\HolidayChecker::class)) {
            return;
        }

        try {
            $checker = new \ChineseHolidays\HolidayChecker();
            $dateStr = $now->format('Y-m-d');

            if ($checker->isHoliday($dateStr)) {
                $info = $checker->getHolidayInfo($dateStr);
                if ($info && isset($info['name'])) {
                    $parts[] = "今天是【{$info['name']}】";
                }
            } elseif (!$checker->isWorkday($dateStr)) {
                $parts[] = '今天是休息日。';
            }
        } catch (\Exception $e) {
        }
    }

    protected function getWeatherInfo(): ?string
    {
        $apiKey = $this->settings->get('flarum-zai-bot.openweather_key');

        if (!$apiKey) {
            return null;
        }

        $city = $this->settings->get('flarum-zai-bot.openweather_city', 'Beijing');

        $cacheFile = $this->getWeatherCachePath();
        $cached = $this->loadWeatherCache($cacheFile);
        if ($cached !== null) {
            return $cached;
        }

        $info = $this->fetchWeather($apiKey, $city);

        if ($info !== null) {
            $this->saveWeatherCache($cacheFile, $info);
        }

        return $info;
    }

    protected function fetchWeather(string $apiKey, string $city): ?string
    {
        try {
            $url = "https://api.openweathermap.org/data/2.5/forecast?q={$city}&appid={$apiKey}&units=metric&lang=zh_cn&cnt=8";

            $response = $this->client->get($url, ['timeout' => 10]);
            $data = json_decode($response->getBody(), true);

            if (empty($data['list'])) {
                return null;
            }

            $output = "【{$city}天气】";

            $current = $data['list'][0] ?? null;
            if ($current) {
                $temp = round($current['main']['temp']);
                $desc = $current['weather'][0]['description'] ?? '';
                $humidity = $current['main']['humidity'];
                $wind = round($current['wind']['speed']);
                $output .= " 当前{$desc}，{$temp}°C，湿度{$humidity}%，风速{$wind}m/s";
            }

            $today = date('Y-m-d');
            $hourly = [];
            foreach ($data['list'] as $entry) {
                if (strpos($entry['dt_txt'], $today) === 0) {
                    $t = substr($entry['dt_txt'], 11, 5);
                    $temp = round($entry['main']['temp']);
                    $desc = $entry['weather'][0]['description'] ?? '';
                    $hourly[] = "{$t} {$temp}°C {$desc}";
                }
            }

            if (!empty($hourly)) {
                $output .= "\n今日小时预报：\n";
                foreach ($hourly as $h) {
                    $output .= "- {$h}\n";
                }
            }

            return trim($output);
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function loadWeatherCache(string $path): ?string
    {
        if (!file_exists($path)) {
            return null;
        }

        $data = json_decode(file_get_contents($path), true);
        if (!$data || !isset($data['timestamp']) || !isset($data['data'])) {
            return null;
        }

        if (time() - $data['timestamp'] > 604800) {
            return null;
        }

        return $data['data'];
    }

    protected function saveWeatherCache(string $path, string $info): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        file_put_contents($path, json_encode([
            'timestamp' => time(),
            'data' => $info,
        ]));
    }

    protected function getWeatherCachePath(): string
    {
        $base = function_exists('storage_path') ? storage_path() : sys_get_temp_dir();
        return $base . '/extensions/flarum-zai-bot/weather.json';
    }

    protected function getAffinityLevel(int $score): string
    {
        return match (true) {
            $score >= 250 => '亲密无间',
            $score >= 200 => '非常友好',
            $score >= 150 => '友善',
            $score >= 100 => '普通',
            $score >= 50  => '冷淡',
            default       => '疏远',
        };
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

    protected function handleToolCalls(array $toolCalls, array $messages, array $tools, string $apiUrl, array $keys, string $model): ?string
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

        $choice = $this->postChat($messages, $toolDefinitions, $apiUrl, $keys, $model);

        if (!$choice) {
            return null;
        }

        $message = $choice['message'] ?? [];

        if (!empty($message['tool_calls'])) {
            $messages[] = $message;
            return $this->handleToolCalls($message['tool_calls'], $messages, $tools, $apiUrl, $keys, $model);
        }

        return $message['content'] ?? null;
    }
}
