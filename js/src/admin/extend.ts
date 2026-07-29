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
            setting: 'flarum-zai-bot.username',
            label: 'Bot Username',
            type: 'text',
            default: 'AIGirl',
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
            setting: 'flarum-zai-bot.system_prompt',
            label: 'System Prompt',
            type: 'textarea',
            default: 'You are a friendly community forum assistant. Keep responses concise and helpful.',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.message_reply_enabled',
            label: 'Enable AI Replies in Messages (requires flarum/messages)',
            type: 'boolean',
            default: false,
        })),
];
