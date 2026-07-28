<?php

namespace Vendor\FlarumZai\Service;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;

class AIService
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Client $client
    ) {}

    public function generateReply(string $prompt, string $context = ''): ?string
    {
        $apiUrl = $this->settings->get('flarum-zai-bot.api_url', 'https://api.openai.com/v1');
        $apiKey = $this->settings->get('flarum-zai-bot.api_key');
        $model = $this->settings->get('flarum-zai-bot.model', 'gpt-3.5-turbo');
        $systemPrompt = $this->settings->get('flarum-zai-bot.system_prompt', 'You are a friendly community forum assistant. Keep responses concise and helpful.');

        if (!$apiKey) {
            return null;
        }

        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        if ($context) {
            $messages[] = ['role' => 'system', 'content' => "Discussion context: $context"];
        }

        $messages[] = ['role' => 'user', 'content' => $prompt];

        try {
            $response = $this->client->post(rtrim($apiUrl, '/') . '/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'messages' => $messages,
                    'max_tokens' => 500,
                ],
                'timeout' => 30,
            ]);

            $body = json_decode($response->getBody(), true);
            return $body['choices'][0]['message']['content'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
