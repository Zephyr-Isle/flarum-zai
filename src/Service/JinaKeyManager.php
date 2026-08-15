<?php

namespace Zephyrisle\FlarumZaiBot\Service;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;

/**
 * Jina AI API Key 管理器。
 *
 * 功能：
 * 1. 管理多个 Jina API Key（逗号分隔存储）
 * 2. 自动轮询：一个 Key 失败时自动切换到下一个
 * 3. 余额监控：检查每个 Key 的 token 余额
 * 4. 低余额通知：当某个 Key 余额不足时发送通知
 */
class JinaKeyManager
{
    private const CHECK_BALANCE_URL = 'https://api.jina.ai/v1/auth/token';

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Client $client
    ) {}

    /**
     * 获取所有 API Keys。
     */
    public function getKeys(): array
    {
        $raw = trim((string) $this->settings->get('flarum-zai-bot.embedding_api_key', ''));
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * 获取当前轮询索引。
     */
    public function getCurrentIndex(): int
    {
        return (int) $this->settings->get('flarum-zai-bot.jina_key_index', 0);
    }

    /**
     * 保存当前轮询索引。
     */
    public function saveIndex(int $index): void
    {
        $this->settings->set('flarum-zai-bot.jina_key_index', (string) $index);
    }

    /**
     * 获取下一个可用的 Key（轮询）。
     */
    public function getNextKey(): ?string
    {
        $keys = $this->getKeys();
        if (empty($keys)) {
            return null;
        }

        $index = $this->getCurrentIndex();
        $key = $keys[$index % count($keys)];

        // 更新索引到下一个
        $this->saveIndex(($index + 1) % count($keys));

        return $key;
    }

    /**
     * 检查所有 Key 的余额。
     *
     * @return array<int, array{key: string, balance: int|null, status: string}>
     */
    public function checkBalances(): array
    {
        $keys = $this->getKeys();
        $results = [];

        foreach ($keys as $index => $key) {
            try {
                $response = $this->client->get(self::CHECK_BALANCE_URL, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $key,
                    ],
                    'timeout' => 10,
                ]);

                $body = json_decode((string) $response->getBody(), true);
                $balance = $body['balance'] ?? $body['tokens'] ?? null;

                $results[] = [
                    'index' => $index,
                    'key' => substr($key, 0, 8) . '...' . substr($key, -4),
                    'balance' => $balance,
                    'status' => $balance > 100000 ? 'ok' : 'low',
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'index' => $index,
                    'key' => substr($key, 0, 8) . '...' . substr($key, -4),
                    'balance' => null,
                    'status' => 'error',
                ];
            }
        }

        return $results;
    }

    /**
     * 检查是否有可用的 Key。
     */
    public function hasAvailableKeys(): bool
    {
        return !empty($this->getKeys());
    }

    /**
     * 获取 Key 数量。
     */
    public function getKeyCount(): int
    {
        return count($this->getKeys());
    }

    /**
     * 添加新的 Key 到配置。
     */
    public function addKey(string $newKey): void
    {
        $keys = $this->getKeys();
        if (!in_array($newKey, $keys, true)) {
            $keys[] = $newKey;
            $this->settings->set('flarum-zai-bot.embedding_api_key', implode(',', $keys));
        }
    }

    /**
     * 从配置中移除 Key。
     */
    public function removeKey(string $keyToRemove): void
    {
        $keys = $this->getKeys();
        $keys = array_filter($keys, fn ($k) => $k !== $keyToRemove);
        $this->settings->set('flarum-zai-bot.embedding_api_key', implode(',', array_values($keys)));
    }
}
