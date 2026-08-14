import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import AffinitiesPage from './components/AffinitiesPage';
import MemoriesPage from './components/MemoriesPage';
import RelationsPage from './components/RelationsPage';
import ExpressionsPage from './components/ExpressionsPage';

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
        // 智能唤醒
        .setting(() => ({
            setting: 'flarum-zai-bot.wake_mention_rules_enabled',
            label: t('wake_mention_rules_enabled_label'),
            help: t('wake_mention_rules_enabled_help'),
            type: 'boolean',
            default: false,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.wake_mention_rules',
            label: t('wake_mention_rules_label'),
            help: t('wake_mention_rules_help'),
            type: 'textarea',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.wake_relevance_enabled',
            label: t('wake_relevance_enabled_label'),
            help: t('wake_relevance_enabled_help'),
            type: 'boolean',
            default: false,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.wake_expert_enabled',
            label: t('wake_expert_enabled_label'),
            help: t('wake_expert_enabled_help'),
            type: 'boolean',
            default: false,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.wake_boredom_enabled',
            label: t('wake_boredom_enabled_label'),
            help: t('wake_boredom_enabled_help'),
            type: 'boolean',
            default: false,
        }))
        // 请求编排：消息合并
        .setting(() => ({
            setting: 'flarum-zai-bot.wake_merge_seconds',
            label: t('wake_merge_seconds_label'),
            help: t('wake_merge_seconds_help'),
            type: 'number',
            default: 0,
            min: 0,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.wake_merge_max',
            label: t('wake_merge_max_label'),
            help: t('wake_merge_max_help'),
            type: 'number',
            default: 5,
            min: 1,
            max: 20,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.wake_merge_require_wake',
            label: t('wake_merge_require_wake_label'),
            help: t('wake_merge_require_wake_help'),
            type: 'boolean',
            default: false,
        }))
        // 媒体解析
        .setting(() => ({
            setting: 'flarum-zai-bot.media_link_parse_enabled',
            label: t('media_link_parse_enabled_label'),
            help: t('media_link_parse_enabled_help'),
            type: 'boolean',
            default: false,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.media_link_blacklist',
            label: t('media_link_blacklist_label'),
            help: t('media_link_blacklist_help'),
            type: 'textarea',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.media_link_timeout',
            label: t('media_link_timeout_label'),
            help: t('media_link_timeout_help'),
            type: 'number',
            default: 8,
            min: 1,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.media_link_max_bytes',
            label: t('media_link_max_bytes_label'),
            help: t('media_link_max_bytes_help'),
            type: 'number',
            default: 524288,
            min: 1024,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.media_link_max_links',
            label: t('media_link_max_links_label'),
            help: t('media_link_max_links_help'),
            type: 'number',
            default: 2,
            min: 1,
            max: 5,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.media_file_parse_enabled',
            label: t('media_file_parse_enabled_label'),
            help: t('media_file_parse_enabled_help'),
            type: 'boolean',
            default: false,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.media_image_classify_enabled',
            label: t('media_image_classify_enabled_label'),
            help: t('media_image_classify_enabled_help'),
            type: 'boolean',
            default: true,
        }))
        // 上下文注入（注入时机 / 事件记录 / 格式与截断）
        .setting(() => ({
            setting: 'flarum-zai-bot.ctx_inject_timing',
            label: t('ctx_inject_timing_label'),
            help: t('ctx_inject_timing_help'),
            type: 'select',
            options: {
                proactive: t('ctx_timing_proactive'),
                all: t('ctx_timing_all'),
                off: t('ctx_timing_off'),
            },
            default: 'proactive',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.ctx_event_record_enabled',
            label: t('ctx_event_record_enabled_label'),
            help: t('ctx_event_record_enabled_help'),
            type: 'boolean',
            default: true,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.ctx_format',
            label: t('ctx_format_label'),
            help: t('ctx_format_help'),
            type: 'select',
            options: {
                concise: t('ctx_format_concise'),
                detailed: t('ctx_format_detailed'),
            },
            default: 'concise',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.ctx_entry_max_chars',
            label: t('ctx_entry_max_chars_label'),
            help: t('ctx_entry_max_chars_help'),
            type: 'number',
            default: 200,
            min: 20,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.ctx_max_events',
            label: t('ctx_max_events_label'),
            help: t('ctx_max_events_help'),
            type: 'number',
            default: 10,
            min: 1,
            max: 50,
        }))
        // 记忆系统（记忆原子 + 混合检索）
        .setting(() => ({
            setting: 'flarum-zai-bot.memory_hybrid_vector_weight',
            label: t('memory_hybrid_vector_weight_label'),
            help: t('memory_hybrid_vector_weight_help'),
            type: 'number',
            default: 60,
            min: 0,
            max: 100,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.memory_decay_days',
            label: t('memory_decay_days_label'),
            help: t('memory_decay_days_help'),
            type: 'number',
            default: 30,
            min: 1,
        }))
        // 细致好感度系统：黑名单熔断阈值
        .setting(() => ({
            setting: 'flarum-zai-bot.affinity_blacklist_threshold',
            label: t('affinity_blacklist_threshold_label'),
            help: t('affinity_blacklist_threshold_help'),
            type: 'number',
            default: 0,
            min: -100,
            max: 0,
        }))
        // 关系网与表达学习开关
        .setting(() => ({
            setting: 'flarum-zai-bot.relation_network_enabled',
            label: t('relation_network_enabled_label'),
            help: t('relation_network_enabled_help'),
            type: 'boolean',
            default: true,
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.expression_learning_enabled',
            label: t('expression_learning_enabled_label'),
            help: t('expression_learning_enabled_help'),
            type: 'boolean',
            default: true,
        }))
        // Embedding 独立配置（不同步 LLM 供应商），默认完全适配 Jina AI
        .setting(() => ({
            setting: 'flarum-zai-bot.embedding_api_url',
            label: t('embedding_api_url_label'),
            help: t('embedding_api_url_help'),
            type: 'text',
            default: 'https://api.jina.ai/v1',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.embedding_api_key',
            label: t('embedding_api_key_label'),
            help: t('embedding_api_key_help'),
            type: 'password',
        }))
        .setting(() => ({
            setting: 'flarum-zai-bot.embedding_model',
            label: t('embedding_model_label'),
            help: t('embedding_model_help'),
            type: 'text',
            default: 'jina-embeddings-v3',
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
        .page(AffinitiesPage)
        .page(MemoriesPage)
        .page(RelationsPage)
        .page(ExpressionsPage),
];
