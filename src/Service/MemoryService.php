<?php

namespace Zephyrisle\FlarumZaiBot\Service;

use Flarum\Settings\SettingsRepositoryInterface;

class MemoryService
{
    protected ?\PDO $pdo = null;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected EmbeddingService $embeddings
    ) {}

    public function isAvailable(): bool
    {
        return $this->getPdo() !== null;
    }

    public function generateEmbedding(string $text): ?array
    {
        return $this->embeddings->generateEmbedding($text);
    }

    public function searchMemories(int $userId, array $queryEmbedding, int $limit = 5): array
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            return [];
        }

        try {
            $vectorStr = '[' . implode(',', $queryEmbedding) . ']';

            $stmt = $pdo->prepare("\n                SELECT content, created_at, 1 - (embedding <=> ?::vector) AS similarity\n                FROM bot_memories\n                WHERE user_id = ?\n                ORDER BY embedding <=> ?::vector\n                LIMIT ?\n            ");

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

            $stmt = $pdo->prepare("\n                INSERT INTO bot_memories (user_id, content, embedding, created_at)\n                VALUES (?, ?, ?::vector, NOW())\n            ");

            $stmt->execute([$userId, $content, $vectorStr]);
            return true;
        } catch (\Exception $e) {
            error_log('[flarum-zai-bot] storeMemory failed (check embedding dimension matches vector(1024)): ' . $e->getMessage());
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
}
