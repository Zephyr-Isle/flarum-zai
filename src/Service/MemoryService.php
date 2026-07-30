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
        protected Client $client
    ) {}

    public function isAvailable(): bool
    {
        return $this->getPdo() !== null;
    }

    public function generateEmbedding(string $text, array $apiKeys): ?array
    {
        $apiUrl = $this->settings->get('flarum-zai-bot.embedding_api_url');
        if (!$apiUrl) {
            $apiUrl = $this->settings->get('flarum-zai-bot.api_url', 'https://api.openai.com/v1');
        }
        $model = $this->settings->get('flarum-zai-bot.embedding_model', 'text-embedding-3-small');

        $lastError = null;
        foreach ($apiKeys as $key) {
            try {
                $response = $this->client->post(rtrim($apiUrl, '/') . '/embeddings', [
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
                    return $embedding;
                }

                return null;
            } catch (\Exception $e) {
                if ($this->isKeyDepleted($e)) {
                    $this->removeApiKey($key);
                }
                $lastError = $e;
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

    protected function removeApiKey(string $key): void
    {
        $raw = $this->settings->get('flarum-zai-bot.embedding_api_keys', '');
        $keys = array_filter(array_map('trim', explode(',', $raw)));
        $keys = array_values(array_filter($keys, fn($k) => $k !== $key));
        $this->settings->set('flarum-zai-bot.embedding_api_keys', implode(',', $keys));
    }
}
