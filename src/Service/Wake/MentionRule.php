<?php

namespace Zephyrisle\FlarumZaiBot\Service\Wake;

/**
 * 提及唤醒规则：关键词（子串匹配）或 re: 正则，可带作用域限定。
 *
 * 规则格式（每行一条，以 # 开头的行视为注释）：
 *   小米                          # 关键词：内容包含“小米”即命中
 *   re:^(?=.*群主)(?=.*笨蛋).*$   # 正则：PCRE，自动加 ~ 定界符并忽略大小写
 *   关键字 @g:123 @u:10001        # 作用域：仅讨论帖 123 且用户 10001
 *   关键字 @g:!456                # 排除：讨论帖 456 不命中，其余全局命中
 *
 * 作用域标记：
 *   @g:群号1,群号2  —— 讨论帖（群）ID 白名单；ID 前加 ! 为黑名单
 *   @u:用户ID1,...  —— 用户 ID 白名单；ID 前加 ! 为黑名单
 *   白名单要求命中，黑名单要求未命中；两种标记同时存在时须同时满足。
 */
final class MentionRule
{
    private ?string $keyword = null;

    private ?string $regex = null;

    /** @var int[] */
    private array $includeDiscussions = [];

    /** @var int[] */
    private array $excludeDiscussions = [];

    /** @var int[] */
    private array $includeUsers = [];

    /** @var int[] */
    private array $excludeUsers = [];

    private string $raw;

    private function __construct(string $raw)
    {
        $this->raw = $raw;
    }

    public static function parse(string $line): ?self
    {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }

        $rule = new self($line);

        // 提取作用域标记 @g:... / @u:...，并从规则文本中剔除
        $pattern = $line;
        if (preg_match_all('/@(g|u):([0-9!,\s]+)/', $line, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $token) {
                foreach (explode(',', $token[2]) as $id) {
                    $id = trim($id);
                    if ($id === '') {
                        continue;
                    }
                    $exclude = str_starts_with($id, '!');
                    $num = (int) ltrim($id, '!');
                    if ($num <= 0) {
                        continue;
                    }
                    if ($token[1] === 'g') {
                        $exclude
                            ? $rule->excludeDiscussions[] = $num
                            : $rule->includeDiscussions[] = $num;
                    } else {
                        $exclude
                            ? $rule->excludeUsers[] = $num
                            : $rule->includeUsers[] = $num;
                    }
                }
            }
            $pattern = trim(preg_replace('/@(g|u):[0-9!,\s]+/', '', $line));
        }

        if (str_starts_with($pattern, 're:')) {
            $regex = trim(substr($pattern, 3));
            if ($regex === '') {
                return null;
            }
            // 校验正则可编译：无效正则会静默永不命中，直接丢弃规则
            $testPattern = '~' . str_replace('~', '\~', $regex) . '~iu';
            if (@preg_match($testPattern, '') === false) {
                return null;
            }
            $rule->regex = $regex;
        } else {
            if ($pattern === '') {
                return null;
            }
            $rule->keyword = $pattern;
        }

        return $rule;
    }

    /**
     * 解析多行规则文本，返回有效规则列表（跳过空行与注释）。
     *
     * @return MentionRule[]
     */
    public static function parseRules(string $raw): array
    {
        $rules = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $rule = self::parse($line);
            if ($rule) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /**
     * 规则是否命中指定消息。
     *
     * @param int      $discussionId 讨论帖 ID（论坛语境下的“群号”）
     * @param int|null $userId       发帖用户 ID，null 表示无法确定（匿名帖）
     */
    public function matches(string $text, int $discussionId, ?int $userId): bool
    {
        // 讨论帖作用域
        if ($this->includeDiscussions !== [] && !in_array($discussionId, $this->includeDiscussions, true)) {
            return false;
        }
        if ($this->excludeDiscussions !== [] && in_array($discussionId, $this->excludeDiscussions, true)) {
            return false;
        }

        // 用户作用域
        if ($userId === null) {
            if ($this->includeUsers !== [] || $this->excludeUsers !== []) {
                return false;
            }
        } else {
            if ($this->includeUsers !== [] && !in_array($userId, $this->includeUsers, true)) {
                return false;
            }
            if ($this->excludeUsers !== [] && in_array($userId, $this->excludeUsers, true)) {
                return false;
            }
        }

        if ($this->regex !== null) {
            // 自动包裹定界符，忽略大小写 + Unicode；转义用户正则中的 ~ 避免破坏定界符
            $pattern = '~' . str_replace('~', '\~', $this->regex) . '~iu';

            return (bool) @preg_match($pattern, $text);
        }

        return $this->keyword !== null && mb_stripos($text, $this->keyword) !== false;
    }

    /**
     * 规则原文（用于日志/展示）。
     */
    public function description(): string
    {
        return $this->raw;
    }
}
