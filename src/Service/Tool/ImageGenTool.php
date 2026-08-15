<?php

namespace Zephyrisle\FlarumZaiBot\Service\Tool;

use Flarum\Http\Client;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * 图片生成工具：通过 Agnes AI API 生成图片。
 *
 * 支持文生图和图生图功能。
 */
class ImageGenTool implements ToolInterface
{
    private const AGNES_API_BASE = 'https://apihub.agnes-ai.cn/v1';

    public function __construct(
        protected Client $client,
        protected SettingsRepositoryInterface $settings
    ) {}

    public function getName(): string
    {
        return 'generate_image';
    }

    public function getDescription(): string
    {
        return '使用 Agnes AI 生成图片。支持文生图（text-to-image）和图生图（image-to-image）。';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'prompt' => [
                    'type' => 'string',
                    'description' => '图片描述（文生图时必填）',
                ],
                'image_urls' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => '输入图片URL数组（图生图时必填）',
                ],
                'size' => [
                    'type' => 'string',
                    'description' => '输出尺寸：1K/2K/3K/4K（默认1K）',
                    'enum' => ['1K', '2K', '3K', '4K'],
                ],
                'ratio' => [
                    'type' => 'string',
                    'description' => '宽高比：1:1/3:4/4:3/16:9/9:16/2:3/3:2/21:9（默认1:1）',
                    'enum' => ['1:1', '3:4', '4:3', '16:9', '9:16', '2:3', '3:2', '21:9'],
                ],
            ],
            'required' => ['prompt'],
        ];
    }

    public function execute(array $args): string
    {
        $apiKey = $this->settings->get('flarum-zai-bot.agnes_api_key', '');
        if (empty($apiKey)) {
            return '未配置 Agnes AI API Key。请在后台设置中配置 flarum-zai-bot.agnes_api_key。';
        }

        $prompt = $args['prompt'] ?? '';
        $imageUrls = $args['image_urls'] ?? [];
        $size = $args['size'] ?? '1K';
        $ratio = $args['ratio'] ?? '1:1';

        if (empty($prompt) && empty($imageUrls)) {
            return '请提供图片描述或输入图片URL。';
        }

        try {
            $requestBody = [
                'model' => 'agnes-image-2.1-flash',
                'prompt' => $prompt,
                'size' => $size,
                'ratio' => $ratio,
                'extra_body' => [
                    'response_format' => 'url',
                ],
            ];

            // 图生图：添加输入图片
            if (!empty($imageUrls)) {
                $requestBody['extra_body']['image'] = $imageUrls;
            }

            $response = $this->client->post(
                self::AGNES_API_BASE . '/images/generations',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $requestBody,
                    'timeout' => 120,
                ]
            );

            $body = json_decode((string) $response->getBody(), true);

            if (empty($body['data'][0]['url'])) {
                return '图片生成失败：未返回图片URL。';
            }

            $imageUrl = $body['data'][0]['url'];
            $revisedPrompt = $body['data'][0]['revised_prompt'] ?? $prompt;

            return "图片生成成功！\n- URL: {$imageUrl}\n- 描述: {$revisedPrompt}\n\n请将此URL嵌入到回复中展示给用户。";
        } catch (\Exception $e) {
            error_log('[flarum-zai-bot] ImageGenTool failed: ' . $e->getMessage());
            return '图片生成失败：' . $e->getMessage();
        }
    }
}
