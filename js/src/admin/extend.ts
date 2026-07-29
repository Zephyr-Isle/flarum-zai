import Extend from 'flarum/common/extenders';
import AffinitiesPage from './components/AffinitiesPage';

export default [
    new Extend.Admin()
        .setting(() => ({
            setting: 'flarum-zai-bot.api_url',
            label: 'zephyrisle-flarum-zai-bot.admin.settings.api_url_label',
            type: 'text',
            default: 'https://api.openai.com/v1',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.api_keys',
            label: 'zephyrisle-flarum-zai-bot.admin.settings.api_keys_label',
            type: 'text',
            default: '',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.api_key',
            label: 'zephyrisle-flarum-zai-bot.admin.settings.api_key_label',
            type: 'password',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.model',
            label: 'zephyrisle-flarum-zai-bot.admin.settings.model_label',
            type: 'text',
            default: 'gpt-3.5-turbo',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.username',
            label: 'zephyrisle-flarum-zai-bot.admin.settings.username_label',
            type: 'text',
            default: 'AIGirl',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.random_reply_chance',
            label: 'zephyrisle-flarum-zai-bot.admin.settings.random_reply_chance_label',
            type: 'number',
            default: 0,
            min: 0,
            max: 100,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.message_extension',
            label: 'zephyrisle-flarum-zai-bot.admin.settings.message_extension_label',
            type: 'boolean',
            default: false,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.system_prompt',
            label: 'zephyrisle-flarum-zai-bot.admin.settings.system_prompt_label',
            type: 'textarea',
            default: 'You are a friendly community forum assistant. Keep responses concise and helpful.',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.message_reply_enabled',
            label: 'zephyrisle-flarum-zai-bot.admin.settings.message_reply_enabled_label',
            type: 'boolean',
            default: false,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.personality',
            label: 'zephyrisle-flarum-zai-bot.admin.settings.personality_label',
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
            label: 'zephyrisle-flarum-zai-bot.admin.settings.bot_display_name_label',
            type: 'text',
            default: 'Yuki',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.timezone',
            label: 'zephyrisle-flarum-zai-bot.admin.settings.timezone_label',
            type: 'text',
            default: 'Asia/Shanghai',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.openweather_key',
            label: 'zephyrisle-flarum-zai-bot.admin.settings.openweather_key_label',
            type: 'password',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.openweather_city',
            label: 'zephyrisle-flarum-zai-bot.admin.settings.openweather_city_label',
            type: 'text',
            default: 'Beijing',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.embedding_api_url',
            label: 'zephyrisle-flarum-zai-bot.admin.settings.embedding_api_url_label',
            type: 'text',
            default: '',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.embedding_model',
            label: 'zephyrisle-flarum-zai-bot.admin.settings.embedding_model_label',
            type: 'text',
            default: 'text-embedding-3-small',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.pgvector_host',
            label: 'zephyrisle-flarum-zai-bot.admin.settings.pgvector_host_label',
            type: 'text',
            default: '',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.pgvector_port',
            label: 'zephyrisle-flarum-zai-bot.admin.settings.pgvector_port_label',
            type: 'text',
            default: '5432',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.pgvector_db',
            label: 'zephyrisle-flarum-zai-bot.admin.settings.pgvector_db_label',
            type: 'text',
            default: '',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.pgvector_user',
            label: 'zephyrisle-flarum-zai-bot.admin.settings.pgvector_user_label',
            type: 'text',
            default: '',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.pgvector_password',
            label: 'zephyrisle-flarum-zai-bot.admin.settings.pgvector_password_label',
            type: 'password',
        }))
        .page(AffinitiesPage),
];
