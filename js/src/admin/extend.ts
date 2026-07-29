import Extend from 'flarum/common/extenders';

export default [
    new Extend.Admin()
        .setting(() => ({
            setting: 'flarum-zai-bot.api_url',
            label: 'API URL',
            type: 'text',
            default: 'https://api.openai.com/v1',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.api_key',
            label: 'API Key',
            type: 'password',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.model',
            label: 'Model',
            type: 'text',
            default: 'gpt-3.5-turbo',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.providers',
            label: 'Providers JSON (overrides API URL/Key/Model)',
            type: 'textarea',
            default: '[]',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.username',
            label: 'Bot Username',
            type: 'text',
            default: 'AIGirl',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.bot_display_name',
            label: 'Bot Display Name',
            type: 'text',
            default: 'Yuki',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.random_reply_chance',
            label: 'Random Reply Chance (%)',
            type: 'number',
            default: 0,
            min: 0,
            max: 100,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.message_extension',
            label: 'Message Extension (reply in active discussions)',
            type: 'boolean',
            default: false,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.personality',
            label: 'Personality',
            type: 'select',
            options: {
                friendly: 'Friendly',
                tsundere: 'Tsundere',
                loli: 'Loli',
                cool: 'Cool',
                custom: 'Custom',
            },
            default: 'friendly',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.system_prompt',
            label: 'System Prompt (only used when Personality = Custom)',
            type: 'textarea',
            default: 'You are a friendly community forum assistant. Keep responses concise and helpful.',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.temperature',
            label: 'Temperature',
            type: 'number',
            default: 0.8,
            min: 0,
            max: 2,
            step: 0.1,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.max_history',
            label: 'Max Conversation History',
            type: 'number',
            default: 10,
            min: 1,
            max: 100,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.message_reply_enabled',
            label: 'Enable AI Replies in Messages (requires flarum/messages)',
            type: 'boolean',
            default: false,
        })),
];
