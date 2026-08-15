<?php

namespace Zephyrisle\FlarumZaiBot\Service\Tool;

/**
 * 颜文字工具：提供丰富的日式文本表情符号（颜文字）。
 *
 * AI 可以通过此工具查询和使用颜文字来表达情感，
 * 让回复更加生动有趣。
 */
class KaomojiTool implements ToolInterface
{
    /**
     * 颜文字库：按情感分类
     */
    private const KAOMOJI = [
        // 喜悦/开心
        'happy' => [
            '(๑•̀ㅂ•́)و✧',
            '(≧▽≦)',
            '(ヽ´▽`)ノ',
            'ヾ(＾∇＾)',
            '(^▽^)/',
            '(◕‿◕✿)',
            '(✿◠‿◠)',
            '♪(´ε` )',
            '(｡◕‿◕｡)',
            '(*^▽^*)',
            '(≧◡≦) ♡',
            '(ﾉ◕ヮ◕)ﾉ*:・ﾟ✧',
            'ヽ(>∀<☆)ノ',
            '(☆▽☆)',
            '(◠‿◠)',
        ],
        // 惊讶
        'surprised' => [
            '(°Д°)',
            '(⊙_⊙)',
            'Σ(°△°|||)',
            '(ﾟДﾟ)',
            '∑(O_O；)',
            '(°ロ°) !',
            '(ﾟoﾟ;)',
            '(⊙ω⊙)',
            'Σ(ﾟдﾟ)',
            '(°ー°〃)',
        ],
        // 悲伤/哭泣
        'sad' => [
            '(╥﹏╥)',
            '(ಥ﹏ಥ)',
            '(ㄒoㄒ)',
            '(；д；)',
            '(T▽T)',
            '(｡•́︿•̀｡)',
            '(´;ω;`)',
            '(╯︵╰,)',
            '(╥_╥)',
            '(ಥ_ಥ)',
        ],
        // 愤怒
        'angry' => [
            '(╯°□°)╯︵ ┻━┻',
            '(╬▔皿▔)╯',
            '(ノಠ益ಠ)ノ彡┻━┻',
            '(¬_¬)',
            '(；一_一)',
            '(눈_눈)',
            '(≖_≖ )',
            '( ´_ゝ`)',
            '(￣^￣)',
            '(¬д¬)',
        ],
        // 害怕/紧张
        'scared' => [
            '(°o°)',
            '(○_○；)',
            'Σ(°△°|||)',
            '(ﾟДﾟ;)',
            '_| ̄|○',
            '(⊙_☉)',
            '(°ロ°)',
            '(∪｡∪)｡｡｡zzz',
            '(×_×;）',
            '(°□°;)',
        ],
        // 爱心/喜欢
        'love' => [
            '(♡´▽`♡)',
            '(´,,•ω•,,)♡',
            '♡(ӦｖӦ｡)',
            '(づ￣ ³￣)づ',
            '(っ˘ω˘ς )',
            '(❤ω❤)',
            '(*´ー`*)',
            '(ˊᗜˋ*)',
            '(｡♥‿♥｡)',
            '♡(*´∀｀*)♡',
        ],
        // 无语/无奈
        'speechless' => [
            '(-_-)',
            '(._. )',
            '(¬_¬ )',
            '(ー_ー)!!',
            '(∪讽∪)',
            '(°ー°〃)',
            '(；一_一)',
            '(￣▽￣)ノ',
            '(-_-;)zzz',
            '(´-ω-`)',
        ],
        // 思考/困惑
        'thinking' => [
            '(・∀・)',
            '(⊙_☉)',
            '(¬_¬ )',
            '(°ー°)',
            '(..??)',
            'Σ(°△°|||)',
            '(・～・)？',
            '(⊙ω⊙)?',
            '(°ロ°)！',
            '(ﾟДﾟ)？',
        ],
        // 打招呼
        'greeting' => [
            '(＾▽＾)/',
            'ヾ(＾∇＾)ﾉ',
            '(｡◕‿◕｡)ﾉ',
            '(ﾉ◕ヮ◕)ﾉ*:・ﾟ✧',
            '(ﾟ∀ﾟ)ﾉ',
            '(*´∀｀*)',
            '(^\_^)／',
            'ヾ(´▽｀;)ゝ',
            '(≧∇≦)ﾉ',
            '(☆▽☆)／',
        ],
        // 嘻嘻/调皮
        'playful' => [
            '(๑•̀ㅂ•́)و',
            '(≖ᴗ≖✿)',
            '(｡•̀ᴗ-)✧',
            '(╯ω╯)',
            '(≧ω≦)',
            '(oﾟvﾟ)ノ',
            '(๑˃ᴗ˂)ﻭ',
            '(ᗒᗨᗕ)',
            '(•̀ᴗ•́)و',
            '(◍•ᴗ•◍)',
        ],
        // 困/累
        'tired' => [
            '(∪｡∪)｡｡｡zzz',
            '(￣o￣) . z Z',
            '(=_=)',
            '( −_−) zzZ',
            '(∪﹏∪)',
            '(´-ω-`)',
            '(..ZZZ)',
            '(−_−) zzZ',
            '(∪｡∪)｡｡｡',
            '(；￣ω￣)',
        ],
        // 吃东西
        'eating' => [
            '(っ˘ڡ˘ς)',
            '(o˘◡˘o)',
            '(⁎˃ᴗ˂⁎)',
            'eating(ˊᗜˋ*)',
            '(ง •̀_•́)ง',
            '(｡♥‿♥｡)🍰',
            '(o^▽^o)🍜',
            '(＾＾)🍜',
            '(ｖ＾▽＾)ｖ',
            '(o˘◡˘o)🍰',
        ],
    ];

    public function getName(): string
    {
        return 'kaomoji';
    }

    public function getDescription(): string
    {
        return '查询和使用颜文字（日式文本表情符号）。可以按情感分类查询，让回复更加生动有趣。';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'description' => '操作类型：random（随机获取）、by_mood（按情感获取）、list_moods（列出所有情感分类）',
                    'enum' => ['random', 'by_mood', 'list_moods'],
                ],
                'mood' => [
                    'type' => 'string',
                    'description' => '情感分类：happy/surprised/sad/angry/scared/love/speechless/thinking/greeting/playful/tired/eating',
                ],
                'count' => [
                    'type' => 'integer',
                    'description' => '返回数量（默认3，最大10）',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $args): string
    {
        $action = $args['action'] ?? 'random';
        $mood = $args['mood'] ?? '';
        $count = max(1, min(10, (int) ($args['count'] ?? 3)));

        switch ($action) {
            case 'random':
                return $this->getRandom($count);
            case 'by_mood':
                return $mood !== '' ? $this->getByMood($mood, $count) : '请指定情感分类。';
            case 'list_moods':
                return $this->listMoods();
            default:
                return '未知操作：' . $action;
        }
    }

    protected function getRandom(int $count): string
    {
        $allKaomoji = [];
        foreach (self::KAOMOJI as $mood => $kaomojiList) {
            foreach ($kaomojiList as $kaomoji) {
                $allKaomoji[] = $kaomoji;
            }
        }

        $selected = [];
        for ($i = 0; $i < $count && $i < count($allKaomoji); $i++) {
            $selected[] = $allKaomoji[array_rand($allKaomoji)];
        }

        return "随机颜文字：\n" . implode("\n", $selected);
    }

    protected function getByMood(string $mood, int $count): string
    {
        $mood = strtolower($mood);

        if (!isset(self::KAOMOJI[$mood])) {
            $validMoods = implode('、', array_keys(self::KAOMOJI));
            return "无效的情感分类「{$mood}」。可用分类：{$validMoods}";
        }

        $kaomojiList = self::KAOMOJI[$mood];
        $selected = [];
        for ($i = 0; $i < $count && $i < count($kaomojiList); $i++) {
            $selected[] = $kaomojiList[array_rand($kaomojiList)];
        }

        $moodLabels = [
            'happy' => '喜悦/开心',
            'surprised' => '惊讶',
            'sad' => '悲伤/哭泣',
            'angry' => '愤怒',
            'scared' => '害怕/紧张',
            'love' => '爱心/喜欢',
            'speechless' => '无语/无奈',
            'thinking' => '思考/困惑',
            'greeting' => '打招呼',
            'playful' => '嘻哈/调皮',
            'tired' => '困/累',
            'eating' => '吃东西',
        ];

        $label = $moodLabels[$mood] ?? $mood;
        return "「{$label}」颜文字：\n" . implode("\n", $selected);
    }

    protected function listMoods(): string
    {
        $moodLabels = [
            'happy' => '喜悦/开心',
            'surprised' => '惊讶',
            'sad' => '悲伤/哭泣',
            'angry' => '愤怒',
            'scared' => '害怕/紧张',
            'love' => '爱心/喜欢',
            'speechless' => '无语/无奈',
            'thinking' => '思考/困惑',
            'greeting' => '打招呼',
            'playful' => '嘻哈/调皮',
            'tired' => '困/累',
            'eating' => '吃东西',
        ];

        $output = "可用情感分类：\n";
        foreach ($moodLabels as $key => $label) {
            $count = count(self::KAOMOJI[$key] ?? []);
            $output .= "- {$key}: {$label} ({$count}个)\n";
        }

        return trim($output);
    }
}
