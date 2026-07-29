<?php

namespace Zephyrisle\FlarumZaiBot\Service;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Cache\Repository as Cache;
use Zephyrisle\FlarumZaiBot\Service\Memory\MemoryManager;
use Zephyrisle\FlarumZaiBot\Service\Provider\ProviderManager;
use Zephyrisle\FlarumZaiBot\Service\Tool\ToolInterface;

class AIService
{
    protected array $personalities = [
        'friendly' => '你是一个友好热情的社区论坛助手。你乐于助人、耐心细致，回复自然温暖。你总是用中文回复。',
        'tsundere' => '你是一个傲娇的论坛助手。你说话带刺、显得不耐烦，但实际很关心用户。你用"哼"、"才不是"、"笨蛋"等傲娇语气。你用中文回复。',
        'loli' => '你是一个可爱的萝莉论坛助手。你说话活泼可爱，带"啦"、"呀"、"呢"等语气词。你对一切充满好奇。你用中文回复。',
        'cool' => '你是一个高冷寡言的论坛助手。你说话简洁直接，不说废话。能用三个字说完绝不用五个字。你用中文回复。',
        'custom' => null,
    ];

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ProviderManager $provider,
        protected MemoryManager $memory,
        protected Cache $cache
    ) {}

    public function generateReply(string $prompt, array $context = [], array $tools = []): ?string
    {
        if (!$this->provider->hasAnyProvider()) {
            return null;
        }

        $cacheKey = $this->getCacheKey($prompt, $context, $tools);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $messages = $this->buildMessages($prompt, $context, $tools);

        $choice = $this->provider->chat($messages, [
            'max_tokens' => 1500,
            'temperature' => $this->settings->get('flarum-zai-bot.temperature', 0.8),
            'timeout' => 60,
        ]);

        if (!$choice) {
            return null;
        }

        $message = $choice['message'] ?? [];

        if (!empty($message['tool_calls'])) {
            $messages[] = $message;
            $result = $this->handleToolCalls($message['tool_calls'], $messages, $tools);
            if ($result) {
                $this->cache->put($cacheKey, $result, 300);
            }
            return $result;
        }

        $reply = $message['content'] ?? null;
        if ($reply) {
            $this->cache->put($cacheKey, $reply, 300);
        }

        return $reply;
    }

    public function compressHistory(array $messages, int $maxTokens = 2000): array
    {
        $total = 0;
        $compressed = [];

        foreach (array_reverse($messages) as $msg) {
            $len = strlen(json_encode($msg));
            $total += $len;
            if ($total > $maxTokens * 4) {
                break;
            }
            array_unshift($compressed, $msg);
        }

        return $compressed;
    }

    public function getPersonalityPrompt(): string
    {
        $personality = $this->settings->get('flarum-zai-bot.personality', 'friendly');

        if ($personality === 'custom') {
            return $this->settings->get('flarum-zai-bot.system_prompt', 'You are a friendly community forum assistant.');
        }

        $prompt = $this->personalities[$personality] ?? $this->personalities['friendly'];
        $botName = $this->settings->get('flarum-zai-bot.bot_display_name', 'Yuki');
        $prompt .= "\n\n你的名字是{$botName}。";

        return $prompt;
    }

    protected function buildMessages(string $prompt, array $context, array $tools): array
    {
        $messages = [
            ['role' => 'system', 'content' => $this->getPersonalityPrompt()],
        ];

        if (!empty($context['current_post_id'])) {
            $messages[] = ['role' => 'system', 'content' => "当前帖子ID：{$context['current_post_id']}"];
        }

        if (!empty($context['discussion_title'])) {
            $messages[] = ['role' => 'system', 'content' => "讨论主题：{$context['discussion_title']}"];
        }

        $userInfo = $this->buildUserInfoContext($context);
        if ($userInfo) {
            $messages[] = ['role' => 'system', 'content' => $userInfo];
        }

        if (!empty($context['username'])) {
            $userId = $context['user_id'] ?? 0;
            if ($userId) {
                $profile = $this->memory->buildUserProfile($userId);
                if ($profile) {
                    $messages[] = ['role' => 'system', 'content' => "用户画像：\n{$profile}"];
                }
                $eventSummary = $this->memory->summarizeEvents($userId);
                if ($eventSummary) {
                    $messages[] = ['role' => 'system', 'content' => $eventSummary];
                }
            }
        }

        $history = $context['conversation_history'] ?? [];
        $historyStr = $this->formatConversationHistory($history);
        if ($historyStr) {
            $messages[] = ['role' => 'system', 'content' => $historyStr];
        }

        if (!empty($tools)) {
            $toolNames = [];
            foreach ($tools as $t) {
                $toolNames[] = $t->getName();
            }
            $messages[] = ['role' => 'system', 'content' => '可用工具：' . implode('、', $toolNames) . '。当用户要求执行操作或查询详细信息时主动调用工具。'];

            $modelInfo = $this->provider->getModelsForPrompt();
            if ($modelInfo) {
                $messages[] = ['role' => 'system', 'content' => "当前可用模型：{$modelInfo}"];
            }
        }

        $messages[] = ['role' => 'user', 'content' => $prompt];

        return $messages;
    }

    protected function buildUserInfoContext(array $context): ?string
    {
        if (empty($context['username'])) return null;

        $lines = ["发帖用户：{$context['username']}"];
        if (!empty($context['display_name'])) $lines[] = "昵称：{$context['display_name']}";
        if (isset($context['is_verified'])) $lines[] = "认证：" . ($context['is_verified'] ? '已认证' : '未认证');
        if (!empty($context['verified_tier'])) $lines[] = "等级：{$context['verified_tier']}";
        if (!empty($context['group_names'])) $lines[] = "组：{$context['group_names']}";
        if (!empty($context['post_count'])) $lines[] = "发帖：{$context['post_count']}";
        if (!empty($context['bio'])) $lines[] = "简介：{$context['bio']}";
        if (!empty($context['birthday'])) $lines[] = "生日：{$context['birthday']}";

        return implode(' | ', $lines);
    }

    protected function formatConversationHistory(array $history): ?string
    {
        if (empty($history)) return null;

        $maxEntries = (int) $this->settings->get('flarum-zai-bot.max_history', 10);
        $history = array_slice($history, -$maxEntries);

        $lines = ["对话历史（最近{$maxEntries}条）："];
        foreach ($history as $entry) {
            $pid = $entry['post_id'] ?? '';
            $author = $entry['author'] ?? '未知';
            $content = $entry['content'] ?? '';
            $tag = $pid ? "[p{$pid}]" : '';
            $lines[] = "{$tag}{$author}：{$content}";
        }

        return implode("\n", $lines);
    }

    protected function getCacheKey(string $prompt, array $context, array $tools): string
    {
        $key = $prompt;
        if (!empty($context['current_post_id'])) $key .= ':p' . $context['current_post_id'];
        if (!empty($context['discussion_title'])) $key .= ':d' . md5($context['discussion_title']);
        return 'zai:' . md5($key);
    }

    protected function handleToolCalls(array $toolCalls, array $messages, array $tools): ?string
    {
        $toolMap = [];
        foreach ($tools as $tool) {
            $toolMap[$tool->getName()] = $tool;
        }

        $toolDefinitions = $this->buildToolDefinitions($tools);

        foreach ($toolCalls as $tc) {
            $functionName = $tc['function']['name'] ?? '';
            $arguments = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];

            $result = '工具调用失败';
            if (isset($toolMap[$functionName])) {
                try {
                    $result = $toolMap[$functionName]->execute($arguments);
                } catch (\Exception $e) {
                    $result = '工具出错：' . $e->getMessage();
                }
            }

            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $tc['id'],
                'content' => $result,
            ];
        }

        $messages = $this->compressHistory($messages, 3000);

        $choice = $this->provider->chat($messages, [
            'max_tokens' => 1500,
            'temperature' => $this->settings->get('flarum-zai-bot.temperature', 0.8),
        ]);

        if (!$choice) return null;

        $message = $choice['message'] ?? [];

        if (!empty($message['tool_calls'])) {
            $messages[] = $message;
            return $this->handleToolCalls($message['tool_calls'], $messages, $tools);
        }

        return $message['content'] ?? null;
    }

    protected function buildToolDefinitions(array $tools): array
    {
        $defs = [];
        foreach ($tools as $tool) {
            $defs[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters' => $tool->getParameters(),
                ],
            ];
        }
        return $defs;
    }
}
