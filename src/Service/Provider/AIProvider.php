<?php

namespace Zephyrisle\FlarumZaiBot\Service\Provider;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;

class AIProvider
{
    protected array $providers = [];
    protected array $costTiers = [
        'cheap' => ['max_tokens' => 300, 'temperature' => 0.7],
        'normal' => ['max_tokens' => 1000, 'temperature' => 0.7],
        'smart' => ['max_tokens' => 2000, 'temperature' => 0.8],
    ];

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Client $client
    ) {
        $this->loadProviders();
    }

    protected function loadProviders(): void
    {
        $raw = $this->settings->get('flarum-zai-bot.providers', '');
        $entries = $raw ? json_decode($raw, true) : [];

        if (empty($entries)) {
            $apiKey = $this->settings->get('flarum-zai-bot.api_key');
            $apiUrl = $this->settings->get('flarum-zai-bot.api_url', 'https://api.openai.com/v1');
            $model = $this->settings->get('flarum-zai-bot.model', 'gpt-3.5-turbo');
            $cheapModel = $this->settings->get('flarum-zai-bot.cheap_model', 'gpt-3.5-turbo');
            $smartModel = $this->settings->get('flarum-zai-bot.smart_model', 'gpt-4o');

            if ($apiKey) {
                $this->providers[] = [
                    'api_key' => $apiKey,
                    'api_url' => rtrim($apiUrl, '/'),
                    'models' => ['cheap' => $cheapModel, 'normal' => $model, 'smart' => $smartModel],
                    'weight' => 100,
                    'name' => 'default',
                ];
            }
            return;
        }

        foreach ($entries as $entry) {
            if (empty($entry['api_key'])) continue;
            $this->providers[] = [
                'api_key' => $entry['api_key'],
                'api_url' => rtrim($entry['api_url'] ?? 'https://api.openai.com/v1', '/'),
                'models' => [
                    'cheap' => $entry['cheap_model'] ?? $entry['model'] ?? 'gpt-3.5-turbo',
                    'normal' => $entry['model'] ?? 'gpt-3.5-turbo',
                    'smart' => $entry['smart_model'] ?? 'gpt-4o',
                ],
                'weight' => (int) ($entry['weight'] ?? 100),
                'name' => $entry['name'] ?? 'unnamed',
            ];
        }
    }

    public function getProvider(string $tier = 'normal'): ?array
    {
        $candidates = [];
        foreach ($this->providers as $p) {
            $candidates = array_merge($candidates, array_fill(0, $p['weight'], $p));
        }

        if (empty($candidates)) return null;

        shuffle($candidates);

        $lastError = null;
        foreach ($candidates as $provider) {
            try {
                $test = $this->client->post("{$provider['api_url']}/chat/completions", [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $provider['api_key'],
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => $provider['models'][$tier] ?? $provider['models']['normal'],
                        'messages' => [['role' => 'user', 'content' => 'ping']],
                        'max_tokens' => 1,
                    ],
                    'timeout' => 5,
                ]);
                if ($test->getStatusCode() === 200) {
                    return $provider;
                }
            } catch (\Exception $e) {
                $lastError = $e;
                continue;
            }
        }

        return $this->providers[0] ?? null;
    }

    public function complete(string $prompt, array $messages, string $tier = 'normal', int $maxTokens = 0): ?array
    {
        $provider = $this->getProvider($tier);
        if (!$provider) return null;

        $tierConfig = $this->costTiers[$tier] ?? $this->costTiers['normal'];
        $model = $provider['models'][$tier] ?? $provider['models']['normal'];

        try {
            $response = $this->client->post("{$provider['api_url']}/chat/completions", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $provider['api_key'],
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'messages' => $messages,
                    'max_tokens' => $maxTokens ?: $tierConfig['max_tokens'],
                    'temperature' => $tierConfig['temperature'],
                ],
                'timeout' => 60,
            ]);

            $body = json_decode($response->getBody(), true);
            return [
                'provider' => $provider['name'],
                'model' => $model,
                'tier' => $tier,
                'body' => $body,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    public function decideTier(string $task): string
    {
        $simple = ['赞', '点', 'hi', 'hello', 'ok', '好', '嗯', '谢谢', 'thank', 'yes', 'no'];
        foreach ($simple as $keyword) {
            if (mb_stripos($task, $keyword) !== false) {
                return 'cheap';
            }
        }

        $complex = ['分析', '总结', '写', 'create', '讨论', '什么', '如何', '为什么', '怎么', 'search'];
        foreach ($complex as $keyword) {
            if (mb_stripos($task, $keyword) !== false) {
                return 'smart';
            }
        }

        return 'normal';
    }
}
