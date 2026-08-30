<?php

namespace Zephyrisle\FlarumZaiBot\Service\Tool;

use Carbon\Carbon;
use Flarum\Post\Post;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * flarum/flags 工具：举报帖子（需管理员权限）。
 */
class FlagTool implements ToolInterface
{
    public function __construct(
        protected ?int $botUserId = null
    ) {}

    public function getName(): string
    {
        return 'flag_post';
    }

    public function getDescription(): string
    {
        return '举报帖子（仅限管理员/版主操作）。当检测到不当内容、垃圾信息或违规行为时使用。';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'description' => '要举报的帖子ID',
                ],
                'reason' => [
                    'type' => 'string',
                    'description' => '举报原因：spam（垃圾信息）、off-topic（离题）、inappropriate（不当内容）、other（其他）',
                    'enum' => ['spam', 'off-topic', 'inappropriate', 'other'],
                ],
            ],
            'required' => ['post_id', 'reason'],
        ];
    }

    public function execute(array $args): string
    {
        if (!class_exists(\Flarum\Flags\Flag::class)) {
            return '未安装 flarum/flags 扩展。';
        }

        $postId = (int) ($args['post_id'] ?? 0);
        $reason = $args['reason'] ?? 'other';

        if (!$postId) {
            return '请提供有效的帖子ID。';
        }

        $post = Post::find($postId);
        if (!$post) {
            return "未找到ID为 {$postId} 的帖子。";
        }

        if (!$this->botUserId) {
            return '机器人用户未初始化。';
        }

        $botUser = \Flarum\User\User::find($this->botUserId);
        if (!$botUser) {
            return '机器人用户不存在。';
        }

        try {
            $botUser->assertCan('flag', $post);
        } catch (\Exception $e) {
            return '机器人没有举报该帖子的权限。';
        }

        // 检查是否已有举报
        $existingFlag = \Flarum\Flags\Flag::where('post_id', $postId)
            ->where('user_id', $this->botUserId)
            ->first();

        if ($existingFlag) {
            return "已对该帖子进行过举报，无需重复操作。";
        }

        // 与 flarum/flags 官方创建流程保持一致：
        //   - type 固定为 'user'（用户举报），原因写入 reason 字段
        //   - Flag 模型关闭了时间戳，必须显式设置 created_at
        //   - 保存后派发 Created 事件，触发版主通知
        $flag = new \Flarum\Flags\Flag();
        $flag->post_id = $postId;
        $flag->user_id = $this->botUserId;
        $flag->type = 'user';
        $flag->reason = $reason;
        $flag->created_at = Carbon::now();
        $flag->save();

        if (class_exists(\Flarum\Flags\Event\Created::class)) {
            try {
                resolve(Dispatcher::class)->dispatch(new \Flarum\Flags\Event\Created($flag, $botUser));
            } catch (\Exception $e) {
                error_log('[flarum-zai-bot] FlagTool: dispatch flag created event failed: ' . $e->getMessage());
            }
        }

        $reasonLabels = [
            'spam' => '垃圾信息',
            'off-topic' => '离题内容',
            'inappropriate' => '不当内容',
            'other' => '其他原因',
        ];

        $reasonLabel = $reasonLabels[$reason] ?? $reason;
        return "已举报帖子 #{$postId}，原因：{$reasonLabel}。版主会收到通知并尽快处理。";
    }
}
