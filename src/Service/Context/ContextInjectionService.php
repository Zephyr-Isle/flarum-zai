<?php

namespace Zephyrisle\FlarumZaiBot\Service\Context;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use Zephyrisle\FlarumZaiBot\Model\ContextEvent;

/**
 * 上下文注入：补齐场景与身份上下文。
 *
 * 在机器人回复前，按配置把“场景/身份环境字段 + 讨论近期事件”聚合成一段上下文
 * 注入给模型（以 system 消息形式，见 AIService 的 injected_context）。
 *
 * 注入时机（ctx_inject_timing）：
 *   - off       关闭，不注入
 *   - proactive 仅主动唤醒前注入（概率/相关性/专业答疑/无聊唤醒，不含显式 @ 与规则）
 *   - all       每次回复前都注入
 *
 * 注入格式（ctx_format）：
 *   - concise  精简：一行一条，不含结构细节
 *   - detailed 详细：携带 msg_id、操作者、回复/引用等结构信息
 *
 * 条目截断（ctx_entry_max_chars）：每条上下文文本超出部分自动截断。
 *
 * 事件记录（ctx_event_record_enabled）：由 RecordContextEvent 监听论坛事件写入
 * bot_context_events 表，本服务按讨论聚合最近 ctx_max_events 条。
 */
class ContextInjectionService
{
    public const TIMING_OFF = 'off';
    public const TIMING_PROACTIVE = 'proactive';
    public const TIMING_ALL = 'all';

    public const FORMAT_CONCISE = 'concise';
    public const FORMAT_DETAILED = 'detailed';

    /** 主动唤醒类型：概率 / 相关性 / 专业答疑 / 无聊唤醒 */
    private const PROACTIVE_TYPES = ['probability', 'relevance', 'expert', 'boredom'];

    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    /**
     * 是否应在当前唤醒场景下注入上下文。
     *
     * @param string|null $wakeType 唤醒类型（mention/rule/probability/relevance/expert/boredom）
     * @param string      $channel  forum / message
     */
    public function shouldInject(?string $wakeType, string $channel = 'forum'): bool
    {
        $timing = (string) $this->settings->get('flarum-zai-bot.ctx_inject_timing', self::TIMING_PROACTIVE);

        if ($timing === self::TIMING_OFF) {
            return false;
        }

        // 私信/聊天无“主动/被动”之分：只要不是关闭就注入
        if (in_array($channel, ['message', 'chat'], true)) {
            return true;
        }

        if ($timing === self::TIMING_ALL) {
            return true;
        }

        // proactive：仅主动唤醒类型注入
        return $wakeType !== null && in_array($wakeType, self::PROACTIVE_TYPES, true);
    }

    /**
     * 构建注入的上下文块；不满足注入时机时返回 null。
     *
     * @param array $context 键：channel, wake_type, discussion_id, discussion_title,
     *                       user_id, username, display_name, group_names
     */
    public function buildInjectedContext(array $context): ?string
    {
        $channel = $context['channel'] ?? 'forum';
        $wakeType = $context['wake_type'] ?? null;

        if (!$this->shouldInject($wakeType, $channel)) {
            return null;
        }

        $format = (string) $this->settings->get('flarum-zai-bot.ctx_format', self::FORMAT_CONCISE);
        $maxChars = max(20, (int) $this->settings->get('flarum-zai-bot.ctx_entry_max_chars', 200));
        $maxEvents = max(1, min(50, (int) $this->settings->get('flarum-zai-bot.ctx_max_events', 10)));

        $lines = [];
        $lines[] = '【讨论上下文】';

        // ===== 环境感知：场景与身份字段 =====
        $lines[] = $this->envLine('平台', 'Flarum 论坛', $maxChars);
        $lines[] = $this->envLine('会话类型', match (true) {
            $channel === 'message' => '私信',
            $channel === 'chat' => '聊天频道',
            default => '论坛讨论帖',
        }, $maxChars);

        if (!empty($context['discussion_id'])) {
            $lines[] = $this->envLine('讨论ID', (string) $context['discussion_id'], $maxChars);
        }
        if (!empty($context['discussion_title'])) {
            $lines[] = $this->envLine('讨论标题', (string) $context['discussion_title'], $maxChars);
        }

        $now = $this->now();
        $weekdayNames = ['日', '一', '二', '三', '四', '五', '六'];
        $weekday = $weekdayNames[(int) $now->format('w')];
        $lines[] = $this->envLine('当前时间', $now->format('Y年m月d日') . ' 星期' . $weekday . ' ' . $now->format('H:i'), $maxChars);

        if (!empty($context['user_id'])) {
            $lines[] = $this->envLine('发送者用户ID', (string) $context['user_id'], $maxChars);
        }
        $identity = [];
        if (!empty($context['display_name'])) {
            $identity[] = (string) $context['display_name'];
        }
        if (!empty($context['username'])) {
            $identity[] = '@' . $context['username'];
        }
        if ($identity) {
            $lines[] = $this->envLine('发送者昵称', implode(' ', $identity), $maxChars);
        }
        if (!empty($context['group_names'])) {
            $lines[] = $this->envLine('用户组', (string) $context['group_names'], $maxChars);
        }

        // ===== 事件记录：讨论近期事件 =====
        if ($channel !== 'message' && !empty($context['discussion_id'])) {
            $events = $this->recentEvents((int) $context['discussion_id'], $maxEvents);

            if (!empty($events)) {
                $lines[] = '';
                $lines[] = '【近期事件】';
                foreach ($events as $event) {
                    $lines[] = $this->formatEvent($event, $format, $maxChars);
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * 查询某讨论最近的事件（正序输出）。
     *
     * @return ContextEvent[]
     */
    protected function recentEvents(int $discussionId, int $limit): array
    {
        if (!class_exists(ContextEvent::class)) {
            return [];
        }

        try {
            return ContextEvent::where('discussion_id', $discussionId)
                ->orderBy('id', 'desc')
                ->take($limit)
                ->get()
                ->reverse()
                ->all();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 将单条事件按格式与截断渲染成一行。
     */
    protected function formatEvent(ContextEvent $event, string $format, int $maxChars): string
    {
        $time = $event->created_at ? $event->created_at->format('H:i') : '';

        if ($format === self::FORMAT_DETAILED) {
            $detail = "msg_id={$event->post_id} type={$event->type}";
            if ($event->user_id) {
                $detail .= " actor={$event->user_id}";
            }
            $text = "[{$time}] {$detail} {$event->description}";
        } else {
            $text = "[{$time}] {$event->description}";
        }

        return '- ' . $this->truncate($text, $maxChars);
    }

    /**
     * 环境字段单行：`- 字段名：值`，值部分按上限截断。
     */
    protected function envLine(string $name, string $value, int $maxChars): string
    {
        return '- ' . $name . '：' . $this->truncate($value, $maxChars);
    }

    /**
     * 超出上限时按字符截断并追加省略号。
     */
    protected function truncate(string $text, int $maxChars): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, $maxChars) . '…';
    }

    protected function now(): Carbon
    {
        try {
            $timezone = $this->settings->get('flarum-zai-bot.timezone', 'Asia/Shanghai');

            return Carbon::now($timezone);
        } catch (\Exception $e) {
            return Carbon::now('Asia/Shanghai');
        }
    }
}
