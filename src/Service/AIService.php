<?php

namespace Zephyrisle\FlarumZaiBot\Service;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Zephyrisle\FlarumZaiBot\Service\Memory\MemoryManager;
use Zephyrisle\FlarumZaiBot\Service\Provider\AIProvider;
use Zephyrisle\FlarumZaiBot\Service\Tool\ToolInterface;

class AIService
{
    protected array $cache = [];

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Client $client,
        protected AIProvider $provider,
        protected MemoryManager $memory,
        protected BotAccountManager $accountManager
    ) {}

    public function generateReply(
        string $prompt,
        array $context = [],
        array $tools = [],
        ?array $account = null
    ): ?string {
        $apiKey = $this->settings->get('flarum-zai-bot.api_key');
        if (!$apiKey) return null;

        $account ??= $this->accountManager->getActiveAccount();
        $tier = $this->provider->decideTier($prompt);

        $cacheKey = $this->getCacheKey($prompt, $context, $account);
        $cached = $this->getCached($cacheKey);
        if ($cached !== null) return $cached;

        $messages = $this->buildMessages($prompt, $context, $account);

        $toolDefinitions = $this->buildToolDefinitions($tools);
        if (!empty($toolDefinitions)) {
            $messages[] = [
                'role' => 'system',
                'content' => '你可以使用以下工具：' . implode('、', array_map(fn ($t) => $t->getName() . '（' . $t->getDescription() . '）', $tools)) . '。当用户需要时主动调用。',
            ];
        }

        $result = $this->provider->complete($prompt, $messages, $tier);

        if (!$result || !isset($result['body']['choices'][0])) {
            return null;
        }

        $message = $result['body']['choices'][0]['message'] ?? [];

        if (!empty($message['tool_calls'])) {
            $messages[] = $message;
            return $this->handleToolCalls($message['tool_calls'], $messages, $tools, $account);
        }

        $reply = $message['content'] ?? null;

        if ($reply) {
            $this->setCached($cacheKey, $reply, $tier);
        }

        return $reply;
    }

    protected function buildMessages(string $prompt, array $context, ?array $account): array
    {
        $systemPrompt = $account
            ? $this->accountManager->getPersonalityPrompt($account)
            : $this->settings->get('flarum-zai-bot.system_prompt', 'You are a friendly community forum assistant.');

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'system', 'content' => '请使用中文回复。'],
        ];

        if (!empty($context['current_post_id'])) {
            $messages[] = ['role' => 'system', 'content' => "当前帖子ID：{$context['current_post_id']}"];
        }

        if (!empty($context['discussion_title'])) {
            $messages[] = ['role' => 'system', 'content' => "当前讨论主题：{$context['discussion_title']}"];
        }

        if (!empty($context['username'])) {
            $userCtx = "当前发帖用户：{$context['display_name']}（@{$context['username']}）";
            if (isset($context['is_verified'])) {
                $userCtx .= "，认证：" . ($context['is_verified'] ? '已认证' : '未认证');
            }
            if (!empty($context['bio'])) {
                $userCtx .= "，简介：{$context['bio']}";
            }
            $messages[] = ['role' => 'system', 'content' => $userCtx];
        }

        $botUserId = $account ? $this->accountManager->getOrCreateBotUser($account['username'])->id : 0;
        if ($botUserId) {
            $memoryCtx = $this->memory->buildMemoryContext($botUserId);
            if ($memoryCtx) {
                $messages[] = ['role' => 'system', 'content' => "记忆：\n{$memoryCtx}"];
            }
        }

        if (!empty($context['conversation_history'])) {
            $history = $this->compressHistory($context['conversation_history']);
            $messages[] = ['role' => 'system', 'content' => "对话历史：\n{$history}"];
        }

        $messages[] = ['role' => 'user', 'content' => $prompt];

        return $messages;
    }

    protected function compressHistory(array $history): string
    {
        $lines = [];
        $totalLen = 0;
        $maxLen = 1500;

        foreach (array_slice($history, -8) as $entry) {
            $line = "- [{$entry['post_id']}] {$entry['author']}：{$entry['content']}";
            $len = mb_strlen($line);
            if ($totalLen + $len > $maxLen) {
                $line = "- [{$entry['post_id']}] {$entry['author']}：" . mb_substr($entry['content'], 0, 100) . '…';
            }
            $lines[] = $line;
            $totalLen += mb_strlen(end($lines));
        }

        return implode("\n", $lines);
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

    protected function getCacheKey(string $prompt, array $context, ?array $account): string
    {
        $key = $prompt;
        if (!empty($context['current_post_id'])) {
            $key .= '_post' . $context['current_post_id'];
        }
        return 'zai_' . md5($key . ($account['username'] ?? ''));
    }

    protected function getCached(string $key): ?string
    {
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        if (class_exists(\Flarum\Cache\CacheManager::class)) {
            try {
                $cache = resolve(\Flarum\Cache\CacheManager::class);
                return $cache->get($key);
            } catch (\Exception $e) {}
        }

        return null;
    }

    protected function setCached(string $key, string $value, string $tier): void
    {
        $this->cache[$key] = $value;

        $ttl = $tier === 'smart' ? 300 : 60;

        if (class_exists(\Flarum\Cache\CacheManager::class)) {
            try {
                $cache = resolve(\Flarum\Cache\CacheManager::class);
                $cache->put($key, $value, $ttl);
            } catch (\Exception $e) {}
        }
    }

    protected function handleToolCalls(array $toolCalls, array $messages, array $tools, ?array $account): ?string
    {
        $toolMap = [];
        foreach ($tools as $tool) {
            $toolMap[$tool->getName()] = $tool;
        }

        foreach ($toolCalls as $tc) {
            $name = $tc['function']['name'] ?? '';
            $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];

            $result = '工具调用失败';
            if (isset($toolMap[$name])) {
                try {
                    $result = $toolMap[$name]->execute($args);
                } catch (\Exception $e) {
                    $result = '工具出错：' . $e->getMessage();
                }
            }

            $botUserId = $account ? $this->accountManager->getOrCreateBotUser($account['username'])->id : 0;
            if ($botUserId) {
                $this->memory->rememberInteraction($botUserId, "调用工具 {$name} 查询「{$args['query'] ?? $args['keyword'] ?? $args['post_id'] ?? ''}」");
            }

            $messages[] = ['role' => 'tool', 'tool_call_id' => $tc['id'], 'content' => $result];
        }

        $tier = $this->provider->decideTier('工具结果处理');
        $result = $this->provider->complete('工具结果处理', $messages, $tier);

        if (!$result || !isset($result['body']['choices'][0])) return null;

        $msg = $result['body']['choices'][0]['message'] ?? [];

        if (!empty($msg['tool_calls'])) {
            $messages[] = $msg;
            return $this->handleToolCalls($msg['tool_calls'], $messages, $tools, $account);
        }

        return $msg['content'] ?? null;
    }
}
