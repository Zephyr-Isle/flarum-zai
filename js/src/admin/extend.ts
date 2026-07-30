import Extend from 'flarum/common/extenders';
import AffinitiesPage from './components/AffinitiesPage';

export default [
    new Extend.Admin()
        .setting(() => ({
            setting: 'flarum-zai-bot.api_url',
            label: 'API URL',
            type: 'text',
            default: 'https://api.openai.com/v1',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.api_keys',
            label: 'API Keys (comma-separated, auto-failover)',
            type: 'text',
            default: '',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.api_key',
            label: 'API Key (fallback if Keys empty)',
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
            setting: 'flarum-zai-bot.bot_display_name',
            label: 'Bot Display Name',
            type: 'text',
            default: 'Yuki',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.timezone',
            label: 'Timezone',
            type: 'text',
            default: 'Asia/Shanghai',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.openweather_key',
            label: 'OpenWeather API Key',
            type: 'password',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.openweather_city',
            label: 'OpenWeather City',
            type: 'text',
            default: 'Beijing',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.embedding_api_keys',
            label: 'Embedding API Keys (comma-separated, separate from LLM keys)',
            type: 'text',
            default: '',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.embedding_api_url',
            label: 'Embedding API URL (leave empty to use main API URL)',
            type: 'text',
            default: '',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.embedding_model',
            label: 'Embedding Model',
            type: 'text',
            default: 'text-embedding-3-small',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.pgvector_host',
            label: 'PostgreSQL Host (for pgvector memory, leave empty to disable)',
            type: 'text',
            default: '',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.pgvector_port',
            label: 'PostgreSQL Port',
            type: 'text',
            default: '5432',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.pgvector_db',
            label: 'PostgreSQL Database',
            type: 'text',
            default: '',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.pgvector_user',
            label: 'PostgreSQL User',
            type: 'text',
            default: '',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.pgvector_password',
            label: 'PostgreSQL Password',
            type: 'password',
        }))
        .page(AffinitiesPage),
];
