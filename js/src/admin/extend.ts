import Extend from 'flarum/common/extenders';

export default [
    new Extend.Admin()
        .setting(() => ({
            setting: 'flarum-zai-bot.api_url',
            label: 'API 地址',
            type: 'text',
            default: 'https://api.openai.com/v1',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.api_key',
            label: 'API 密钥',
            type: 'password',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.bot_display_name',
            label: '机器人显示名称',
            type: 'text',
            default: 'Yuki',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.random_reply_chance',
            label: '随机回复概率（%）',
            type: 'number',
            default: 0,
            min: 0,
            max: 100,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.auto_engage',
            label: '主动参与讨论',
            type: 'boolean',
            default: false,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.auto_engage_chance',
            label: '主动参与概率（%）',
            type: 'number',
            default: 20,
            min: 0,
            max: 100,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.message_reply_enabled',
            label: '启用私信回复（需 flarum/messages）',
            type: 'boolean',
            default: false,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.memory_ttl',
            label: '记忆保留天数',
            type: 'number',
            default: 30,
            min: 1,
            max: 365,
        })),
];
