<?php

namespace Zephyrisle\FlarumZaiBot\Service\Tool;

use Flarum\Http\Client;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * 视频生成工具：通过 Agnes AI API 生成视频。
 *
 * 支持文生视频和图生视频功能。
 * 使用异步任务 API：先创建任务，再轮询获取结果。
 */
class VideoGenTool implements ToolInterface
{
    private const AGNES_API_BASE = 'https://apihub.agnes-ai.cn/v1';

    public function __construct(
        protected Client $client,
        protected SettingsRepositoryInterface $settings
    ) {}

    public function getName(): string
    {
        return 'generate_video';
    }

    public function getDescription(): string
    {
        return '使用 Agnes AI 生成视频。支持文生视频（text-to-video）和图生视频（image-to-video）。视频生成是异步的，需要轮询获取结果。';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'description' => '操作类型：create（创建任务）、check（查询结果）',
                    'enum' => ['create', 'check'],
                ],
                'prompt' => [
                    'type' => 'string',
                    'description' => '视频描述（action为create时必填）',
                ],
                'image_url' => [
                    'type' => 'string',
                    'description' => '输入图片URL（图生视频时必填）',
                ],
                'task_id' => [
                    'type' => 'string',
                    'description' => '任务ID（action为check时必填）',
                ],
                'width' => [
                    'type' => 'integer',
                    'description' => '视频宽度（默认1152）',
                ],
                'height' => [
                    'type' => 'integer',
                    'description' => '视频高度（默认768）',
                ],
                'num_frames' => [
                    'type' => 'integer',
                    'description' => '帧数（默认121，必须<=441且遵循8n+1规则）',
                ],
                'frame_rate' => [
                    'type' => 'integer',
                    'description' => '帧率（默认24，范围1-60）',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $args): string
    {
        $apiKey = $this->settings->get('flarum-zai-bot.agnes_api_key', '');
        if (empty($apiKey)) {
            return '未配置 Agnes AI API Key。请在后台设置中配置 flarum-zai-bot.agnes_api_key。';
        }

        $action = $args['action'] ?? 'create';

        switch ($action) {
            case 'create':
                return $this->createTask($args, $apiKey);
            case 'check':
                return $this->checkTask($args, $apiKey);
            default:
                return '未知操作：' . $action;
        }
    }

    protected function createTask(array $args, string $apiKey): string
    {
        $prompt = $args['prompt'] ?? '';
        $imageUrl = $args['image_url'] ?? '';
        $width = $args['width'] ?? 1152;
        $height = $args['height'] ?? 768;
        $numFrames = $args['num_frames'] ?? 121;
        $frameRate = $args['frame_rate'] ?? 24;

        if (empty($prompt)) {
            return '请提供视频描述。';
        }

        try {
            $requestBody = [
                'model' => 'agnes-video-v2.0',
                'prompt' => $prompt,
                'width' => $width,
                'height' => $height,
                'num_frames' => $numFrames,
                'frame_rate' => $frameRate,
            ];

            // 图生视频：添加输入图片
            if (!empty($imageUrl)) {
                $requestBody['image'] = $imageUrl;
            }

            $response = $this->client->post(
                self::AGNES_API_BASE . '/videos',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $requestBody,
                    'timeout' => 60,
                ]
            );

            $body = json_decode((string) $response->getBody(), true);

            if (empty($body['task_id'])) {
                return '视频任务创建失败：未返回任务ID。';
            }

            $taskId = $body['task_id'];
            $videoId = $body['video_id'] ?? $taskId;
            $status = $body['status'] ?? 'unknown';
            $seconds = $body['seconds'] ?? 'unknown';
            $size = $body['size'] ?? 'unknown';

            return "视频任务创建成功！\n- 任务ID: {$taskId}\n- 视频ID: {$videoId}\n- 状态: {$status}\n- 时长: {$seconds}秒\n- 分辨率: {$size}\n\n请使用 check 操作查询任务状态，task_id: {$taskId}";
        } catch (\Exception $e) {
            error_log('[flarum-zai-bot] VideoGenTool create failed: ' . $e->getMessage());
            return '视频任务创建失败：' . $e->getMessage();
        }
    }

    protected function checkTask(array $args, string $apiKey): string
    {
        $taskId = $args['task_id'] ?? '';

        if (empty($taskId)) {
            return '请提供任务ID。';
        }

        try {
            $response = $this->client->get(
                self::AGNES_API_BASE . '/agnesapi?video_id=' . $taskId,
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                    ],
                    'timeout' => 30,
                ]
            );

            $body = json_decode((string) $response->getBody(), true);

            $status = $body['status'] ?? 'unknown';
            $progress = $body['progress'] ?? 0;

            if ($status === 'completed') {
                $videoUrl = $body['metadata']['url'] ?? '';
                $seconds = $body['seconds'] ?? 'unknown';
                $size = $body['size'] ?? 'unknown';

                if (!empty($videoUrl)) {
                    return "视频生成完成！\n- URL: {$videoUrl}\n- 时长: {$seconds}秒\n- 分辨率: {$size}\n\n请将此URL嵌入到回复中展示给用户。";
                }
                return '视频生成完成，但未返回URL。';
            }

            if ($status === 'failed') {
                $error = $body['error'] ?? '未知错误';
                return "视频生成失败：{$error}";
            }

            return "视频生成中...\n- 状态: {$status}\n- 进度: {$progress}%\n\n请稍后再次查询。";
        } catch (\Exception $e) {
            error_log('[flarum-zai-bot] VideoGenTool check failed: ' . $e->getMessage());
            return '查询任务状态失败：' . $e->getMessage();
        }
    }
}
