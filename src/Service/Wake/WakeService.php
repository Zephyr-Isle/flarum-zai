<?php

namespace Zephyrisle\FlarumZaiBot\Service\Wake;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * 唤醒判定结果。
 */
final class WakeDecision
{
    public function __construct(
        public readonly bool $reply,
        public readonly ?string $type = null,
        public readonly int $score = 0,
        public readonly string $reason = ''
    ) {
    }
}

/**
 * 智能唤醒：从“被动等艾特”到“根据语境主动参与”。
 *
 * 触发判定（本类负责，全部本地启发式，不消耗模型调用）：
 *   - mention    显式/空 @ 机器人
 *   - rule       提及规则（关键词 / re: 正则 + @g: 讨论帖 / @u: 用户作用域）
 *   - probability 概率唤醒（复用 random_reply_chance 设置）
 *   - relevance  相关性唤醒（与上文话题的关联度，对超短消息/问句降噪，按消息长度动态选历史条数）
 *   - expert     专业答疑（“提问门槛 + 语义评分”两段式）
 *   - boredom    无聊唤醒（冷场/求聊天，前置过滤长叙述）
 *
 * 节奏优化（对启发式唤醒的最终得分做调整）：
 *   - 提及他人 / 回复他人帖子 → 降权
 *   - 复读上文片段 → 额外降权
 *   - Bot 长时间未参与话题 → 轻微沉默补偿
 *
 * 显式触发（mention / rule / probability）不受节奏降权影响，直接回复。
 *
 * 后置判定（模型二次过滤）与唤醒延长（活跃窗口）不在本类范围，见后续阶段。
 */
class WakeService
{
    /** 相关性：与历史帖子共享的字符二元组数量阈值 */
    private const RELEVANCE_THRESHOLD = 2;

    /** 专业答疑：提问门槛通过后命中的求助关键词数量阈值 */
    private const EXPERT_THRESHOLD = 2;

    /** 无聊唤醒：命中任意信号词即触发 */
    private const BOREDOM_THRESHOLD = 1;

    /** 相关性唤醒对超短消息的降噪长度（字符） */
    private const RELEVANCE_MIN_LENGTH = 4;

    /** 无聊唤醒对长叙述的前置过滤长度（字符） */
    private const BOREDOM_MAX_LENGTH = 120;

    /** 节奏优化：提及他人扣分 */
    private const PENALTY_MENTION_OTHER = 2;

    /** 节奏优化：回复/引用他人帖子扣分 */
    private const PENALTY_REPLY_TO_POST = 2;

    /** 节奏优化：复读上文片段额外扣分 */
    private const PENALTY_REPEAT = 2;

    /** 节奏优化：长时间未参与话题的轻微沉默补偿 */
    private const BONUS_SILENCE = 1;

    /** 多久未回复算“长时间未参与”（秒） */
    private const SILENCE_SECONDS = 6 * 3600;

    /** @var string[] */
    private const QUESTION_WORDS = [
        '吗', '呢', '么', '怎么', '为什么', '如何', '啥', '什么', '怎样', '哪',
        '能否', '能不能', '有没有', '谁', '求', '请问', '请教',
    ];

    /** @var string[] */
    private const EXPERT_KEYWORDS = [
        '帮我', '帮忙', '求助', '请教', '请问', '怎么办', '如何', '怎么',
        '大佬', '大神', '谁知道', '谁懂', '推荐', '能不能', '解决', '求',
    ];

    /** @var string[] */
    private const BOREDOM_KEYWORDS = [
        '好无聊', '无聊', '有人吗', '有人嘛', '在吗', '来聊天', '水群',
        '没人理', '寂寞', '求聊天', '冷场', '好安静', '都没人',
    ];

    private bool $rulesLoaded = false;

    /** @var MentionRule[]|null */
    private ?array $rulesCache = null;

    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    /**
     * 综合判定当前消息是否需要唤醒。
     *
     * @param string      $content      帖子纯文本（已去 HTML 标签）
     * @param int         $discussionId 讨论帖 ID
     * @param int|null    $userId       发帖用户 ID
     * @param array       $history      对话历史 [['content' => ..., 'author' => ...]]
     * @param int         $randomChance 概率唤醒百分比（0 关闭）
     * @param string      $botUsername  机器人用户名（显式 @ 匹配）
     * @param int|null    $secondsSinceLastBotReply 距上次机器人回复秒数，null = 从未回复
     * @param bool        $repliesToPost 是否回复/引用其他帖子
     * @param int         $historyCount 实际可用的历史条数
     */
    public function detect(
        string $content,
        int $discussionId,
        ?int $userId,
        array $history,
        int $randomChance,
        string $botUsername,
        ?int $secondsSinceLastBotReply,
        bool $repliesToPost,
        int $historyCount
    ): WakeDecision {
        $content = trim($content);

        // 1) 显式/空 @ 机器人：必回
        if (preg_match('/@' . preg_quote($botUsername, '/') . '\b/i', $content)) {
            return new WakeDecision(true, 'mention');
        }

        // 2) 提及规则（关键词 / 正则 + 作用域）
        foreach ($this->rules() as $rule) {
            if ($rule->matches($content, $discussionId, $userId)) {
                return new WakeDecision(true, 'rule', 1, 'rule: ' . $rule->description());
            }
        }

        // 3) 概率唤醒
        if ($randomChance > 0 && random_int(1, 100) <= $randomChance) {
            return new WakeDecision(true, 'probability', 0, "random {$randomChance}%");
        }

        // 4-6) 启发式唤醒（互斥：按优先级依次尝试）。得分大于 0 即记录类型，
        // 是否真的唤醒交给 7) 的节奏优化做最终裁决（沉默补偿可能补足临界分）。
        $score = 0;
        $type = null;
        $reason = 'no wake condition';

        if ($this->enabled('wake_relevance_enabled')) {
            [$score, $reason] = $this->relevanceScore($content, $history, $historyCount);
            $type = $score > 0 ? 'relevance' : null;
        }

        if ($type === null && $this->enabled('wake_expert_enabled')) {
            [$score, $reason] = $this->expertScore($content);
            $type = $score > 0 ? 'expert' : null;
        }

        if ($type === null && $this->enabled('wake_boredom_enabled')) {
            [$score, $reason] = $this->boredomScore($content);
            $type = $score > 0 ? 'boredom' : null;
        }

        if ($type === null) {
            return new WakeDecision(false, null, 0, $reason);
        }

        // 7) 节奏优化：对启发式唤醒的得分做降权/补偿，低于阈值则不唤醒
        $modifier = $this->rhythmModifier($content, $history, $repliesToPost, $secondsSinceLastBotReply);
        $final = $score + $modifier;

        $threshold = match ($type) {
            'relevance' => self::RELEVANCE_THRESHOLD,
            'expert' => self::EXPERT_THRESHOLD,
            'boredom' => self::BOREDOM_THRESHOLD,
            default => 1,
        };

        if ($final < $threshold) {
            return new WakeDecision(false, null, $final, "{$type} suppressed by rhythm ({$final} < {$threshold})");
        }

        return new WakeDecision(true, $type, $final, $reason . " | rhythm modifier {$modifier}");
    }

    /**
     * 相关性唤醒：与最近历史帖子的内容重叠度。
     * 对超短消息与问句降噪；按消息长度动态决定参与对比的历史条数。
     *
     * @return array{0: int, 1: string}
     */
    protected function relevanceScore(string $content, array $history, int $historyCount): array
    {
        $length = mb_strlen($content);

        if ($length < self::RELEVANCE_MIN_LENGTH) {
            return [0, 'too short'];
        }

        if ($this->isQuestion($content)) {
            return [0, 'question (denoised)'];
        }

        // 消息越长可参考的历史越多：每 50 字符多参考 1 条
        $use = max(1, min($historyCount, 1 + intdiv($length, 50)));
        $needle = $this->bigrams($content);

        $recent = array_slice($history, -$use);
        foreach ($recent as $entry) {
            $shared = count(array_intersect($needle, $this->bigrams((string) ($entry['content'] ?? ''))));
            if ($shared >= self::RELEVANCE_THRESHOLD) {
                return [min(3, $shared), "overlap {$shared} with recent history"];
            }
        }

        return [0, 'no overlap with history'];
    }

    /**
     * 专业答疑：两段式“提问门槛 + 语义评分”。
     * 先确认是问句/求助信号，再统计求助关键词数量。
     *
     * @return array{0: int, 1: string}
     */
    protected function expertScore(string $content): array
    {
        if (!$this->isQuestion($content)) {
            return [0, 'not a question'];
        }

        $score = 0;
        foreach (self::EXPERT_KEYWORDS as $keyword) {
            if (mb_stripos($content, $keyword) !== false) {
                $score++;
            }
        }

        return [min(3, $score), "question + {$score} help keywords"];
    }

    /**
     * 无聊唤醒：前置过滤长叙述，再匹配冷场/求聊天信号词。
     *
     * @return array{0: int, 1: string}
     */
    protected function boredomScore(string $content): array
    {
        if (mb_strlen($content) > self::BOREDOM_MAX_LENGTH) {
            return [0, 'long narrative (filtered)'];
        }

        foreach (self::BOREDOM_KEYWORDS as $keyword) {
            if (mb_stripos($content, $keyword) !== false) {
                return [1, "boredom signal: {$keyword}"];
            }
        }

        return [0, 'no boredom signal'];
    }

    /**
     * 节奏优化：对启发式唤醒得分返回修正量（负数为降权，正数为补偿）。
     */
    protected function rhythmModifier(
        string $content,
        array $history,
        bool $repliesToPost,
        ?int $secondsSinceLastBotReply
    ): int {
        $modifier = 0;

        // 提及他人（非机器人，机器人 @ 已在上层命中 return）→ 降权
        if (preg_match('/@[^\s@]+/', $content)) {
            $modifier -= self::PENALTY_MENTION_OTHER;
        }

        // 回复/引用他人帖子 → 降权
        if ($repliesToPost) {
            $modifier -= self::PENALTY_REPLY_TO_POST;
        }

        // 复读相关性上下文片段 → 额外降权
        $normalized = $this->normalize($content);
        if ($normalized !== '') {
            foreach ($history as $entry) {
                if ($this->normalize((string) ($entry['content'] ?? '')) === $normalized) {
                    $modifier -= self::PENALTY_REPEAT;
                    break;
                }
            }
        }

        // Bot 长时间未参与话题 → 轻微沉默补偿
        if ($secondsSinceLastBotReply === null || $secondsSinceLastBotReply > self::SILENCE_SECONDS) {
            $modifier += self::BONUS_SILENCE;
        }

        return $modifier;
    }

    /**
     * 提及规则列表（懒加载，受开关控制）。
     *
     * @return MentionRule[]
     */
    protected function rules(): array
    {
        if (!$this->rulesLoaded) {
            $this->rulesCache = $this->enabled('wake_mention_rules_enabled')
                ? MentionRule::parseRules((string) $this->settings->get('flarum-zai-bot.wake_mention_rules', ''))
                : [];
            $this->rulesLoaded = true;
        }

        return $this->rulesCache ?? [];
    }

    protected function isQuestion(string $content): bool
    {
        if (preg_match('/[?？]$/u', $content)) {
            return true;
        }

        foreach (self::QUESTION_WORDS as $word) {
            if (mb_strpos($content, $word) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 将文本拆为字符二元组（忽略空白与标点、转小写），用于内容重叠比较。
     *
     * @return string[]
     */
    protected function bigrams(string $text): array
    {
        $text = mb_strtolower(preg_replace('/[\s\p{P}\p{S}]+/u', '', $text));
        $length = mb_strlen($text);

        if ($length === 0) {
            return [];
        }
        if ($length === 1) {
            return [$text];
        }

        $grams = [];
        for ($i = 0; $i < $length - 1; $i++) {
            $grams[] = mb_substr($text, $i, 2);
        }

        return $grams;
    }

    /**
     * 归一化文本（去空白标点、转小写），用于复读检测。
     */
    protected function normalize(string $text): string
    {
        return mb_strtolower(preg_replace('/[\s\p{P}\p{S}]+/u', '', $text));
    }

    protected function enabled(string $key): bool
    {
        return (bool) $this->settings->get('flarum-zai-bot.' . $key, false);
    }
}
