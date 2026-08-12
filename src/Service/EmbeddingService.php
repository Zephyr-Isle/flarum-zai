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

    public function apiKey(): string
    {
        return trim((string) $this->settings->get('flarum-zai-bot.embedding_api_key', ''));
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
     * 对文本生成 embedding 向量；未配置密钥或请求失败时返回 null。
     */
    public function generateEmbedding(string $text): ?array
    {
        if (!$this->isConfigured()) {
            error_log('[flarum-zai-bot] generateEmbedding: embedding API key not configured.');
            return null;
        }

        $model = $this->model();

        try {
            $response = $this->client->post($this->apiUrl() . '/embeddings', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => $this->payload($text),
                'timeout' => 15,
            ]);

            $body = json_decode($response->getBody(), true);
            $embedding = $body['data'][0]['embedding'] ?? null;

            if (is_array($embedding) && count($embedding) > 0) {
                return $embedding;
            }

            error_log('[flarum-zai-bot] generateEmbedding: empty embedding returned. body=' . json_encode($body));
            return null;
        } catch (\Exception $e) {
            error_log('[flarum-zai-bot] generateEmbedding failed: ' . $e->getMessage() . ' | model: ' . $model);
            return null;
        }
    }
}
