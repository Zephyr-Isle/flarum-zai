<?php

namespace Zephyrisle\FlarumZaiBot\Service\Provider;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class ProviderManager
{
    protected array $providers;
    protected array $keyIndex = [];
    protected int $currentIndex = 0;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Client $client
    ) {
        $this->loadProviders();
    }

    public function chat(array $messages, array $options = []): ?array
    {
        if (empty($this->keyIndex)) {
            return null;
        }

        $attempts = count($this->keyIndex);
        $startIndex = $this->currentIndex;

        for ($i = 0; $i < $attempts; $i++) {
            $idx = ($startIndex + $i) % count($this->keyIndex);
            $entry = $this->keyIndex[$idx];

            try {
                $response = $this->client->post(rtrim($entry['base_url'], '/') . '/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $entry['key'],
                        'Content-Type' => 'application/json',
                    ],
                    'json' => array_merge([
                        'model' => $entry['model'],
                        'messages' => $messages,
                        'max_tokens' => $options['max_tokens'] ?? 1500,
                        'temperature' => $options['temperature'] ?? 0.8,
                    ], $entry['extra_params'] ?? []),
                    'timeout' => $options['timeout'] ?? 60,
                ]);

                $this->currentIndex = $idx;
                $body = json_decode($response->getBody(), true);
                return $body['choices'][0] ?? null;
            } catch (GuzzleException $e) {
                continue;
            }
        }

        return null;
    }

    public function getModelsForPrompt(): string
    {
        $names = [];
        foreach ($this->providers as $p) {
            if (!($p['enabled'] ?? false)) continue;
            foreach ($p['keys'] as $k) {
                $names[] = $p['name'] . '/' . ($k['model'] ?? $p['models'][0] ?? 'default');
            }
        }
        return implode(', ', array_unique($names));
    }

    public function hasAnyProvider(): bool
    {
        return !empty($this->keyIndex);
    }

    protected function loadProviders(): void
    {
        $raw = $this->settings->get('flarum-zai-bot.providers');
        $this->providers = $raw ? json_decode($raw, true) : $this->defaultProviders();

        $this->keyIndex = [];
        foreach ($this->providers as $p) {
            if (!($p['enabled'] ?? false)) continue;
            $baseUrl = $p['base_url'] ?? 'https://api.openai.com/v1';
            $models = $p['models'] ?? ['gpt-3.5-turbo'];
            foreach ($p['keys'] as $k) {
                $weight = max(1, (int) ($k['weight'] ?? 1));
                for ($w = 0; $w < $weight; $w++) {
                    $this->keyIndex[] = [
                        'key' => $k['key'],
                        'base_url' => $baseUrl,
                        'model' => $k['model'] ?? $models[0],
                        'extra_params' => $p['extra_params'] ?? [],
                    ];
                }
            }
        }

        shuffle($this->keyIndex);
    }

    protected function defaultProviders(): array
    {
        $apiKey = $this->settings->get('flarum-zai-bot.api_key');
        $apiUrl = $this->settings->get('flarum-zai-bot.api_url', 'https://api.openai.com/v1');
        $model = $this->settings->get('flarum-zai-bot.model', 'gpt-3.5-turbo');

        if (!$apiKey) return [];

        return [
            [
                'name' => 'default',
                'label' => 'Default',
                'base_url' => $apiUrl,
                'enabled' => true,
                'models' => [$model],
                'keys' => [
                    ['key' => $apiKey, 'weight' => 1],
                ],
            ],
        ];
    }
}
