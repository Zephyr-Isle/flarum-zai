<?php

namespace Zephyrisle\FlarumZaiBot\Service\Tool;

use Flarum\Post\Post;

/**
 * fof/drafts 工具：查询/管理用户草稿。
 */
class DraftTool implements ToolInterface
{
    public function __construct(
        protected ?int $userId = null
    ) {}

    public function getName(): string
    {
        return 'manage_drafts';
    }

    public function getDescription(): string
    {
        return '管理用户的草稿。action为"list"时列出草稿，为"get"时获取指定草稿内容，为"delete"时删除草稿。';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'description' => '操作类型：list（列出草稿）、get（获取内容）、delete（删除草稿）',
                    'enum' => ['list', 'get', 'delete'],
                ],
                'draft_id' => [
                    'type' => 'integer',
                    'description' => '草稿ID（action为get或delete时必填）',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $args): string
    {
        if (!class_exists('\FoF\Drafts\Draft')) {
            return '未安装 fof/drafts 扩展。';
        }

        if (!$this->userId) {
            return '用户未登录，无法管理草稿。';
        }

        $action = $args['action'] ?? 'list';
        $draftId = (int) ($args['draft_id'] ?? 0);

        switch ($action) {
            case 'list':
                return $this->listDrafts();
            case 'get':
                return $draftId ? $this->getDraft($draftId) : '请提供草稿ID。';
            case 'delete':
                return $draftId ? $this->deleteDraft($draftId) : '请提供草稿ID。';
            default:
                return '未知操作：' . $action;
        }
    }

    protected function listDrafts(): string
    {
        try {
            $drafts = \FoF\Drafts\Draft::where('user_id', $this->userId)
                ->orderBy('updated_at', 'desc')
                ->take(10)
                ->get();

            if ($drafts->isEmpty()) {
                return '暂无草稿。';
            }

            $output = "最近草稿（最多显示10条）：\n";
            foreach ($drafts as $draft) {
                $title = $draft->title ?: '（无标题）';
                $updatedAt = $draft->updated_at ? $draft->updated_at->format('Y-m-d H:i') : '未知';
                $output .= "- [{$draft->id}] {$title} (更新于 {$updatedAt})\n";
            }

            return trim($output);
        } catch (\Exception $e) {
            return '获取草稿列表失败：' . $e->getMessage();
        }
    }

    protected function getDraft(int $draftId): string
    {
        try {
            $draft = \FoF\Drafts\Draft::where('id', $draftId)
                ->where('user_id', $this->userId)
                ->first();

            if (!$draft) {
                return "未找到ID为 {$draftId} 的草稿。";
            }

            $output = "草稿详情：\n";
            $output .= "- 标题：" . ($draft->title ?: '（无标题）') . "\n";
            $output .= "- 类型：" . ($draft->type ?: 'discussion') . "\n";
            $output .= "- 内容摘要：" . mb_substr(strip_tags((string) $draft->content), 0, 500) . "\n";
            $output .= "- 创建时间：" . ($draft->created_at ? $draft->created_at->format('Y-m-d H:i') : '未知') . "\n";
            $output .= "- 更新时间：" . ($draft->updated_at ? $draft->updated_at->format('Y-m-d H:i') : '未知') . "\n";

            if ($draft->discussion_id) {
                $output .= "- 关联讨论ID：{$draft->discussion_id}\n";
            }

            return trim($output);
        } catch (\Exception $e) {
            return '获取草稿详情失败：' . $e->getMessage();
        }
    }

    protected function deleteDraft(int $draftId): string
    {
        try {
            $draft = \FoF\Drafts\Draft::where('id', $draftId)
                ->where('user_id', $this->userId)
                ->first();

            if (!$draft) {
                return "未找到ID为 {$draftId} 的草稿。";
            }

            $title = $draft->title ?: '（无标题）';
            $draft->delete();

            return "已删除草稿「{$title}」(ID: {$draftId})。";
        } catch (\Exception $e) {
            return '删除草稿失败：' . $e->getMessage();
        }
    }
}
