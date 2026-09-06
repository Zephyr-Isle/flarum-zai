<?php

namespace Zephyrisle\FlarumZaiBot\Service\Text;

/**
 * AI 客户端粘贴占位符处理。
 *
 * 用户在 AI 聊天客户端（如 opencode / Claude Code 的 TUI）里粘贴大段文本时，
 * 客户端会把原文折叠成 "[Pasted ~7 lines]" / "[pasted #2 1+ lines]" 之类的
 * 占位符，占位符会作为消息内容被保存并发送出去。若不处理，AI 机器人可能把
 * 占位符当作真实文本复述出来，甚至写进长期记忆。
 *
 * normalize()  用于进入模型之前：把占位符替换成说明文字，避免模型复述或误解；
 * scrubReply() 用于机器人最终回复：清除其回复中残留的占位符，兜底防止泄露。
 */
class PastePlaceholder
{
    /** 匹配粘贴占位符：[Pasted ~7 lines]、[pasted 4 lines]、[pasted #2 1+ lines] 等 */
    private const PATTERN = '/\[pasted[^\]]*\]/i';

    /** 进入模型前的占位符替换文案 */
    private const INPUT_REPLACEMENT = '（注：对方粘贴了大段文本，客户端仅以占位符形式保存，原文未保留，请不要复述或重复该占位符）';

    /** 模型回复兜底用的占位符替换文案 */
    private const REPLY_REPLACEMENT = '（粘贴内容略）';

    /**
     * 替换文本中的粘贴占位符为说明文字。
     * 用于帖子/私信/聊天内容、对话历史与记忆文本进入模型之前。
     */
    public static function normalize(string $text): string
    {
        if ($text === '' || !str_contains(strtolower($text), 'pasted')) {
            return $text;
        }

        return preg_replace(self::PATTERN, self::INPUT_REPLACEMENT, $text) ?? $text;
    }

    /**
     * 清除机器人最终回复中残留的粘贴占位符，防止把占位符原样发送给用户。
     */
    public static function scrubReply(string $text): string
    {
        if ($text === '' || !str_contains(strtolower($text), 'pasted')) {
            return $text;
        }

        return preg_replace(self::PATTERN, self::REPLY_REPLACEMENT, $text) ?? $text;
    }
}