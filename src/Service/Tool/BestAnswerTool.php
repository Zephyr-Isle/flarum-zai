<?php

namespace Zephyrisle\FlarumZaiBot\Service\Tool;

use Flarum\Post\Post;

/**
 * fof/best-answer 工具：查询/标记/取消讨论的最佳回答。
 */
class BestAnswerTool implements ToolInterface
{
    public function __construct(
        protected ?int $botUserId = null
    ) {}

    public function getName(): string
    {
        return 'best_answer';
    }

    public function getDescription(): string
    {
        return '管理讨论的最佳回答。action为"query"时查询当前最佳回答，为"set"时将指定帖子设为最佳回答，为"unset"时取消最佳回答。';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'discussion_id' => [
                    'type' => 'integer',
                    'description' => '讨论ID',
                ],
                'post_id' => [
                    'type' => 'integer',
                    'description' => '帖子ID（action为set时必填）',
                ],
                'action' => [
                    'type' => 'string',
                    'description' => '操作类型：query（查询）、set（标记）、unset（取消）',
                    'enum' => ['query', 'set', 'unset'],
                ],
            ],
            'required' => ['discussion_id'],
        ];
    }

    public function execute(array $args): string
    {
        // fof/best-answer 没有 Models 目录，用其事件类探测是否安装
        if (!class_exists(\FoF\BestAnswer\Events\BestAnswerSet::class)) {
            return '未安装 fof/best-answer 扩展。';
        }

        $discussionId = (int) ($args['discussion_id'] ?? 0);
        $postId = (int) ($args['post_id'] ?? 0);
        $action = $args['action'] ?? 'query';

        if (!$discussionId) {
            return '请提供有效的讨论ID。';
        }

        $discussion = \Flarum\Discussion\Discussion::find($discussionId);
        if (!$discussion) {
            return "未找到ID为 {$discussionId} 的讨论。";
        }

        if ($action === 'query') {
            return $this->queryBestAnswer($discussion);
        }

        if ($action === 'unset') {
            return $this->unsetBestAnswer($discussion);
        }

        if ($action === 'set' && $postId) {
            return $this->setBestAnswer($discussion, $postId);
        }

        return '标记最佳回答需要提供帖子ID（post_id）。';
    }

    protected function queryBestAnswer($discussion): string
    {
        $bestAnswerId = $discussion->best_answer_post_id ?? null;

        if (!$bestAnswerId) {
            return "讨论「{$discussion->title}」暂无最佳回答。";
        }

        $post = Post::find($bestAnswerId);
        if (!$post) {
            return "最佳回答帖子（ID: {$bestAnswerId}）不存在。";
        }

        $author = $post->user ? $post->user->display_name : '未知';
        $content = mb_substr(strip_tags((string) $post->content), 0, 200);

        return "讨论「{$discussion->title}」的最佳回答：\n- 帖子ID：{$post->id}\n- 作者：{$author}\n- 内容摘要：{$content}";
    }

    protected function setBestAnswer($discussion, int $postId): string
    {
        $post = Post::find($postId);
        if (!$post) {
            return "未找到ID为 {$postId} 的帖子。";
        }

        if ((int) $post->discussion_id !== (int) $discussion->id) {
            return "帖子 {$postId} 不属于讨论 {$discussion->id}。";
        }

        // fof/best-answer 仅允许楼主本人或持有 selectBestAnswer 权限的用户设置最佳回答，
        // 机器人（普通成员组）需要管理员显式授权后才能执行。
        $botUser = \Flarum\User\User::find($this->botUserId ?? null);
        if ($botUser) {
            try {
                $botUser->assertCan('selectBestAnswer', $discussion);
            } catch (\Exception $e) {
                return '机器人没有设置最佳回答的权限（需要 selectBestAnswer 权限）。';
            }
        }

        $discussion->best_answer_post_id = $postId;
        $discussion->save();

        $author = $post->user ? $post->user->display_name : '未知';
        return "已将帖子 #{$postId}（作者：{$author}）标记为讨论「{$discussion->title}」的最佳回答。";
    }

    protected function unsetBestAnswer($discussion): string
    {
        if (!$discussion->best_answer_post_id) {
            return "讨论「{$discussion->title}」暂无最佳回答，无需取消。";
        }

        $botUser = \Flarum\User\User::find($this->botUserId ?? null);
        if ($botUser) {
            try {
                $botUser->assertCan('selectBestAnswer', $discussion);
            } catch (\Exception $e) {
                return '机器人没有取消最佳回答的权限（需要 selectBestAnswer 权限）。';
            }
        }

        $discussion->best_answer_post_id = null;
        $discussion->save();

        return "已取消讨论「{$discussion->title}」的最佳回答。";
    }
}
