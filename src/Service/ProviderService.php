<?php

namespace Zephyrisle\FlarumZaiBot\Service;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * 多供应商配置解析。
 *
 * 供应商配置存于 `flarum-zai-bot.providers`（JSON 数组，每个元素一个供应商）：
 *   [
 *     {"name": "DeepSeek", "api_url": "https://api.deepseek.com/v1", "api_keys": "sk-a,sk-b", "model": "deepseek-chat", "enabled": true},
 *     {"name": "OpenAI",   "api_url": "https://api.openai.com/v1",   "api_keys": "sk-c",         "model": "gpt-4o-mini"}
 *   ]
 *
 * 旧版设置（api_url / api_keys / model / embedding_api_url / embedding_api_keys）
 * 已删除，LLM 与 Embedding 请求都只从 providers 构建端点。
 *
 * 每个供应商的每个密钥都会展开为一个独立端点，调用方按顺序逐个尝试实现自动回退，
 * 并用轮询起始索引（last_*_key_index）实现多个端点间的负载均衡。
 */
class ProviderService
{
    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {}

    /**
     * LLM 对话端点列表：[{name, api_url, api_key, model}]
     */
    public function chatEndpoints(): array
    {
        // 兼容旧 JSON 配置：供应商未指定 model 时，回退到已不再在后台展示的旧版 model 设置（若仍存在于数据库中）
        $defaultModel = $this->settings->get('flarum-zai-bot.model', 'gpt-4o-mini');

        return $this->flatten($this->providers(), $defaultModel);
    }

    /**
     * Embedding 端点列表：[{name, api_url, api_key}]（模型统一使用 embedding_model 设置）。
     */
    public function embeddingEndpoints(): array
    {
        return $this->flatten($this->providers(), null);
    }

    public function embeddingModel(): string
    {
        return $this->settings->get('flarum-zai-bot.embedding_model', 'text-embedding-3-small');
    }

    /**
     * 已启用的供应商配置（原始数组，不做扁平化）。
     */
    public function providers(): array
    {
        $raw = $this->settings->get('flarum-zai-bot.providers', '');

        if (empty($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            error_log('[flarum-zai-bot] providers setting is not valid JSON.');
            return [];
        }

        return array_values(array_filter($decoded, function ($p) {
            return is_array($p)
                && ($p['enabled'] ?? true)
                && !empty($p['api_url'])
                && !empty($this->parseKeys($p['api_keys'] ?? ''));
        }));
    }

    /**
     * 轮询起始索引：基于上次成功端点索引 +1，实现多个端点间的轮询。
     */
    public function nextStartIndex(string $lastIndexKey, int $count): int
    {
        if ($count <= 0) {
            return 0;
        }

        $lastIndex = (int) $this->settings->get($lastIndexKey, -1);

        return ($lastIndex + 1) % $count;
    }

    /**
     * 从 startIndex 起轮转端点列表，实现多个端点间的负载均衡。
     * startIndex <= 0 时原样返回。
     */
    public function rotateEndpoints(array $endpoints, int $startIndex): array
    {
        $count = count($endpoints);
        if ($count === 0 || $startIndex <= 0) {
            return $endpoints;
        }

        return array_merge(array_slice($endpoints, $startIndex), array_slice($endpoints, 0, $startIndex));
    }

    /**
     * 记录成功使用的端点索引（索引相对原始端点列表）。
     */
    public function saveIndex(string $lastIndexKey, array $originalEndpoints, array $usedEndpoint): void
    {
        foreach ($originalEndpoints as $i => $endpoint) {
            if (($endpoint['api_key'] ?? null) === ($usedEndpoint['api_key'] ?? null)
                && ($endpoint['api_url'] ?? null) === ($usedEndpoint['api_url'] ?? null)
                && ($endpoint['model'] ?? null) === ($usedEndpoint['model'] ?? null)) {
                $this->settings->set($lastIndexKey, (string) $i);
                return;
            }
        }
    }

    /**
     * 从供应商配置中移除已耗尽的密钥。
     */
    public function removeApiKey(string $apiKey): void
    {
        $raw = $this->settings->get('flarum-zai-bot.providers', '');

        if (empty($raw)) {
            return;
        }

        $providers = json_decode($raw, true);

        if (!is_array($providers)) {
            return;
        }

        $changed = false;
        foreach ($providers as &$provider) {
            if (!is_array($provider)) {
                continue;
            }
            $keys = $this->parseKeys($provider['api_keys'] ?? '');
            if (in_array($apiKey, $keys, true)) {
                $keys = array_values(array_filter($keys, fn ($k) => $k !== $apiKey));
                $provider['api_keys'] = implode(',', $keys);
                $changed = true;
            }
        }
        unset($provider);

        if ($changed) {
            // 顺带清理已没有任何密钥的供应商，避免 JSON 中长期残留空壳条目
            $providers = array_values(array_filter($providers, function ($p) {
                return is_array($p) && !empty($this->parseKeys($p['api_keys'] ?? ''));
            }));
            $this->settings->set('flarum-zai-bot.providers', json_encode($providers));
        }
    }

    protected function flatten(array $providers, ?string $defaultModel): array
    {
        $endpoints = [];

        foreach ($providers as $provider) {
            $url = rtrim($provider['api_url'] ?? '', '/');
            $model = $provider['model'] ?? $defaultModel;
            foreach ($this->parseKeys($provider['api_keys'] ?? '') as $key) {
                $endpoint = [
                    'name' => $provider['name'] ?? 'Provider',
                    'api_url' => $url,
                    'api_key' => $key,
                ];
                if ($model !== null) {
                    $endpoint['model'] = $model;
                }
                $endpoints[] = $endpoint;
            }
        }

        return $endpoints;
    }

    protected function parseKeys(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
