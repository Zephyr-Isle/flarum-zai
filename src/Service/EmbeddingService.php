<?php

namespace Zephyrisle\FlarumZaiBot\Service;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;

/**
 * 独立的 Embedding 配置与调用。
 *
 * Embedding 不再复用 LLM 供应商列表，而是使用独立的一组设置
 * （flarum-zai-bot.embedding_api_url / embedding_api_key / embedding_model），
 * 默认完全适配 Jina AI：
 *   - 端点：https://api.jina.ai/v1/embeddings（OpenAI 兼容）
 *   - 模型：jina-embeddings-v3（默认输出 1024 维，与 pgvector 列 vector(1024) 一致）
 *   - 附加 task=text-matching 提升语义相似度检索质量
 */
class EmbeddingService
{
    public const DEFAULT_API_URL = 'https://api.jina.ai/v1';
    public const DEFAULT_MODEL = 'jina-embeddings-v3';
    public const DEFAULT_DIMENSIONS = 1024;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Client $client
    ) {}

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    public function apiUrl(): string
    {
        return rtrim((string) $this->settings->get('flarum-zai-bot.embedding_api_url', self::DEFAULT_API_URL), '/');
    }

    /**
     * 获取 API Key：支持单个密钥或逗号分隔的多个密钥。
     * 返回第一个可用的密钥（用于单次请求）。
     */
    public function apiKey(): string
    {
        $keys = $this->apiKeys();
        return $keys[0] ?? '';
    }

    /**
     * 获取所有 API Keys（支持逗号分隔的多个密钥）。
     */
    public function apiKeys(): array
    {
        $raw = trim((string) $this->settings->get('flarum-zai-bot.embedding_api_key', ''));
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public function model(): string
    {
        return (string) $this->settings->get('flarum-zai-bot.embedding_model', self::DEFAULT_MODEL);
    }

    /**
     * 请求体。jina-embeddings-v3 额外携带 Jina 专属参数：
     * task=text-matching（检索场景）与显式 dimensions（固定 1024，防默认值漂移）。
     */
    public function payload(string $text): array
    {
        $payload = [
            'model' => $this->model(),
            'input' => $text,
        ];

        if (str_contains($this->model(), 'jina-embeddings-v3')) {
            $payload['task'] = 'text-matching';
            $payload['dimensions'] = self::DEFAULT_DIMENSIONS;
        }

        return $payload;
    }

    /**
     * 获取轮询起始索引（基于上次成功密钥索引 +1）。
     */
    protected function nextStartIndex(int $count): int
    {
        if ($count <= 0) {
            return 0;
        }

        $lastIndex = (int) $this->settings->get('flarum-zai-bot.last_embedding_key_index', -1);
        return ($lastIndex + 1) % $count;
    }

    /**
     * 记录成功使用的密钥索引。
     */
    protected function saveIndex(int $originalCount, int $usedIndex): void
    {
        $this->settings->set('flarum-zai-bot.last_embedding_key_index', (string) $usedIndex);
    }

    /**
     * 对文本生成 embedding 向量；支持多密钥自动回退与轮询。
     */
    public function generateEmbedding(string $text): ?array
    {
        $keys = $this->apiKeys();
        if (empty($keys)) {
            error_log('[flarum-zai-bot] generateEmbedding: embedding API key not configured.');
            return null;
        }

        $model = $this->model();
        $payload = $this->payload($text);
        $originalCount = count($keys);
        $startIndex = $this->nextStartIndex($originalCount);

        // 轮询：从 startIndex 开始，依次尝试所有密钥
        for ($attempt = 0; $attempt < $originalCount; $attempt++) {
            $index = ($startIndex + $attempt) % $originalCount;
            $apiKey = $keys[$index];

            try {
                $response = $this->client->post($this->apiUrl() . '/embeddings', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'json' => $payload,
                    'timeout' => 15,
                ]);

                $body = json_decode($response->getBody(), true);
                $embedding = $body['data'][0]['embedding'] ?? null;

                if (is_array($embedding) && count($embedding) > 0) {
                    // 成功：记录使用的密钥索引
                    $this->saveIndex($originalCount, $index);
                    return $embedding;
                }

                error_log('[flarum-zai-bot] generateEmbedding: empty embedding returned. body=' . json_encode($body));
            } catch (\Exception $e) {
                error_log('[flarum-zai-bot] generateEmbedding failed (key index ' . $index . '): ' . $e->getMessage() . ' | model: ' . $model);
                continue;
            }
        }

        error_log('[flarum-zai-bot] generateEmbedding exhausted all ' . $originalCount . ' API keys.');
        return null;
    }
}
