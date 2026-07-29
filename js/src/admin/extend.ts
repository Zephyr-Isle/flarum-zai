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
        })),
    new Extend.Admin()
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
            setting: 'flarum-zai-bot.personality',
            label: 'Personality',
            type: 'select',
            options: {
                friendly: 'Friendly (友善)',
                tsundere: 'Tsundere (傲娇)',
                loli: 'Loli (萝莉)',
                cool: 'Cool (高冷)',
                student: 'Student (学生)',
                elder: 'Elder (前辈)',
                tech: 'Tech (技术宅)',
            },
            default: 'friendly',
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
            setting: 'flarum-zai-bot.auto_engage',
            label: 'Auto Engage (autonomously join discussions)',
            type: 'boolean',
            default: false,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.auto_engage_chance',
            label: 'Auto Engage Chance (%)',
            type: 'number',
            default: 20,
            min: 0,
            max: 100,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.message_reply_enabled',
            label: 'Enable AI Replies in Messages (requires flarum/messages)',
            type: 'boolean',
            default: false,
        })),
    new Extend.Admin()
        .setting(() => ({
            setting: 'flarum-zai-bot.cheap_model',
            label: 'Cheap Model (for simple replies)',
            type: 'text',
            default: 'gpt-3.5-turbo',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.smart_model',
            label: 'Smart Model (for complex tasks)',
            type: 'text',
            default: 'gpt-4o',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.provider_list',
            label: 'Provider List (JSON array of {name, api_url, api_key, weight})',
            type: 'textarea',
            default: '[{"name":"openai","api_url":"https://api.openai.com/v1","api_key":"","weight":10}]',
        })),
    new Extend.Admin()
        .setting(() => ({
            setting: 'flarum-zai-bot.accounts',
            label: 'Bot Accounts (JSON array: [{username, personality?, active_hours?, weekdays?, active_chance?, custom_prompt?}])',
            type: 'textarea',
            default: '[{"username":"AIGirl"}]',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.memory_ttl',
            label: 'Memory TTL (days)',
            type: 'number',
            default: 30,
            min: 1,
            max: 365,
        })),
];
