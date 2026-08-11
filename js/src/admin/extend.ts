import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import AffinitiesPage from './components/AffinitiesPage';

const t = (key: string, params?: any) => app.translator.trans('zephyrisle-flarum-zai-bot.admin.settings.' + key, params);

export default [
    // 显式指定 context，确保 registry 的命名空间与扩展 id（zephyrisle-flarum-zai-bot）
    // 一致。若不指定，将回退到运行时的 flarum.extensions 键（部分 Flarum 版本
    // 使用 vendor/package 斜杠形式），导致设置项注册到错误的命名空间而无设置项显示。
    new Extend.Admin('zephyrisle-flarum-zai-bot')
        // 供应商图形化配置（ProvidersSettings）由自定义页 AffinitiesPage 直接渲染，
        // 内部读写 flarum-zai-bot.providers（JSON），随设置表单一起保存。
        .setting(() => ({
            setting: 'flarum-zai-bot.username',
            label: t('username_label'),
            type: 'text',
            default: 'AIGirl',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.random_reply_chance',
            label: t('random_reply_chance_label'),
            type: 'number',
            default: 0,
            min: 0,
            max: 100,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.reply_cooldown',
            label: t('reply_cooldown_label'),
            help: t('reply_cooldown_help'),
            type: 'number',
            default: 30,
            min: 0,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.system_prompt',
            label: t('system_prompt_label'),
            type: 'textarea',
            default: 'You are a friendly community forum assistant. Keep responses concise and helpful.',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.message_reply_enabled',
            label: t('message_reply_enabled_label'),
            type: 'boolean',
            default: false,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.bot_display_name',
            label: t('bot_display_name_label'),
            type: 'text',
            default: 'Yuki',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.timezone',
            label: t('timezone_label'),
            type: 'text',
            default: 'Asia/Shanghai',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.jina_optimization_mode',
            label: t('jina_optimization_mode_label'),
            help: t('jina_optimization_mode_help'),
            type: 'boolean',
            default: false,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.jina_proxy_url',
            label: t('jina_proxy_url_label'),
            help: t('jina_proxy_url_help'),
            type: 'text',
            default: '',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.jina_use_builtin_proxy',
            label: t('jina_use_builtin_proxy_label'),
            help: t('jina_use_builtin_proxy_help'),
            type: 'boolean',
            default: false,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.openweather_key',
            label: t('openweather_key_label'),
            type: 'password',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.openweather_city',
            label: t('openweather_city_label'),
            type: 'text',
            default: 'Beijing',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.embedding_model',
            label: t('embedding_model_label'),
            help: t('embedding_model_help'),
            type: 'text',
            default: 'text-embedding-3-small',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.pgvector_host',
            label: t('pgvector_host_label'),
            help: t('pgvector_host_help'),
            type: 'text',
            default: '',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.pgvector_port',
            label: t('pgvector_port_label'),
            type: 'text',
            default: '5432',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.pgvector_db',
            label: t('pgvector_db_label'),
            type: 'text',
            default: '',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.pgvector_user',
            label: t('pgvector_user_label'),
            type: 'text',
            default: '',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.pgvector_password',
            label: t('pgvector_password_label'),
            type: 'password',
        }))
        .page(AffinitiesPage),
];
