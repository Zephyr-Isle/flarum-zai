<?php

namespace Zephyrisle\FlarumZaiBot\Service;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;

class MemoryService
{
    protected ?\PDO $pdo = null;
    protected ?string $embeddingUrl = null;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Client $client,
        protected ProviderService $providers
    ) {}

    public function isAvailable(): bool
    {
        return $this->getPdo() !== null;
    }

    public function generateEmbedding(string $text): ?array
    {
        $endpoints = $this->providers->embeddingEndpoints();

        if (empty($endpoints)) {
            return null;
        }

        $model = $this->settings->get('flarum-zai-bot.embedding_model', 'text-embedding-3-small');

        // 轮询起始索引：多个端点间负载均衡（上一个成功端点之后开始）
        $startIndex = $this->providers->nextStartIndex('flarum-zai-bot.last_embedding_key_index', count($endpoints));
        $rotatedEndpoints = $this->providers->rotateEndpoints($endpoints, $startIndex);

        foreach ($rotatedEndpoints as $endpoint) {
            $key = $endpoint['api_key'];

            try {
                $response = $this->client->post(rtrim($endpoint['api_url'], '/') . '/embeddings', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $key,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'json' => [
                        'model' => $model,
                        'input' => $text,
                    ],
                    'timeout' => 15,
                ]);

                $body = json_decode($response->getBody(), true);
                $embedding = $body['data'][0]['embedding'] ?? null;

                if ($embedding) {
                    $this->providers->saveIndex('flarum-zai-bot.last_embedding_key_index', $endpoints, $endpoint);
                    return $embedding;
                }

                error_log('[flarum-zai-bot] generateEmbedding: empty embedding returned for endpoint. body=' . json_encode($body));
                continue;
            } catch (\Exception $e) {
                if ($this->isKeyDepleted($e)) {
                    $this->providers->removeApiKey($key);
                }
                continue;
            }
        }

        return null;
    }

    public function searchMemories(int $userId, array $queryEmbedding, int $limit = 5): array
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            return [];
        }

        try {
            $vectorStr = '[' . implode(',', $queryEmbedding) . ']';

            $stmt = $pdo->prepare("
                SELECT content, created_at, 1 - (embedding <=> ?::vector) AS similarity
                FROM bot_memories
                WHERE user_id = ?
                ORDER BY embedding <=> ?::vector
                LIMIT ?
            ");

            $stmt->execute([$vectorStr, $userId, $vectorStr, $limit]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }

    public function storeMemory(int $userId, string $content, array $embedding): bool
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            return false;
        }

        try {
            $vectorStr = '[' . implode(',', $embedding) . ']';

            $stmt = $pdo->prepare("
                INSERT INTO bot_memories (user_id, content, embedding, created_at)
                VALUES (?, ?, ?::vector, NOW())
            ");

            $stmt->execute([$userId, $content, $vectorStr]);
            return true;
        } catch (\Exception $e) {
            error_log('[flarum-zai-bot] storeMemory failed (check embedding dimension matches vector(1536)): ' . $e->getMessage());
            return false;
        }
    }

    protected function getPdo(): ?\PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $host = $this->settings->get('flarum-zai-bot.pgvector_host');
        if (!$host) {
            return null;
        }

        $port = $this->settings->get('flarum-zai-bot.pgvector_port', '5432');
        $db = $this->settings->get('flarum-zai-bot.pgvector_db');
        $user = $this->settings->get('flarum-zai-bot.pgvector_user');
        $pass = $this->settings->get('flarum-zai-bot.pgvector_password');

        if (!$db || !$user) {
            return null;
        }

        try {
            $dsn = "pgsql:host={$host};port={$port};dbname={$db}";
            $this->pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
            return $this->pdo;
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function isKeyDepleted(\Exception $e): bool
    {
        if ($e instanceof \GuzzleHttp\Exception\ClientException) {
            $statusCode = $e->getResponse()->getStatusCode();
            if (in_array($statusCode, [402, 403])) return true;
            $body = json_decode((string)$e->getResponse()->getBody(), true);
            $msg = $body['error']['message'] ?? $body['error']['code'] ?? '';
            return (bool)preg_match('/insufficient|quota|exhausted|payment|balance/i', $msg);
        }
        return false;
    }

}

