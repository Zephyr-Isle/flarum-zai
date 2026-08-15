<?php

use Flarum\Extend;
use Zephyrisle\FlarumZaiBot\Listener\ReplyToMessage;
use Zephyrisle\FlarumZaiBot\Listener\ReplyToPost;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/less/admin.less'),

    (new Extend\Event())
        ->listen(Flarum\Post\Event\Posted::class, ReplyToPost::class)
        // 上下文注入：事件记录（帖子撤回/恢复/删除/编辑，讨论创建/改名/隐藏/恢复/删除）
        // 注意：监听器必须用类名字符串注册（Flarum 解析后调用 handle()），
        // [Class::class, 'method'] 数组对非静态方法不是合法 callable，会导致启动 TypeError。
        ->listen(Flarum\Post\Event\Hidden::class, \Zephyrisle\FlarumZaiBot\Listener\RecordContextEvent::class)
        ->listen(Flarum\Post\Event\Restored::class, \Zephyrisle\FlarumZaiBot\Listener\RecordContextEvent::class)
        ->listen(Flarum\Post\Event\Deleted::class, \Zephyrisle\FlarumZaiBot\Listener\RecordContextEvent::class)
        ->listen(Flarum\Post\Event\Revised::class, \Zephyrisle\FlarumZaiBot\Listener\RecordContextEvent::class)
        ->listen(Flarum\Discussion\Event\Started::class, \Zephyrisle\FlarumZaiBot\Listener\RecordContextEvent::class)
        ->listen(Flarum\Discussion\Event\Renamed::class, \Zephyrisle\FlarumZaiBot\Listener\RecordContextEvent::class)
        ->listen(Flarum\Discussion\Event\Hidden::class, \Zephyrisle\FlarumZaiBot\Listener\RecordContextEvent::class)
        ->listen(Flarum\Discussion\Event\Restored::class, \Zephyrisle\FlarumZaiBot\Listener\RecordContextEvent::class)
        ->listen(Flarum\Discussion\Event\Deleted::class, \Zephyrisle\FlarumZaiBot\Listener\RecordContextEvent::class),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-messages', fn () => [
            (new Extend\Event())
                ->listen(\Flarum\Messages\DialogMessage\Event\Created::class, ReplyToMessage::class),
        ]),

    // fof/merge-discussions: 记录讨论合并事件到上下文
    (new Extend\Conditional())
        ->whenExtensionEnabled('fof-merge-discussions', fn () => [
            (new Extend\Event())
                ->listen(\FoF\MergeDiscussions\Events\DiscussionWasMerged::class, \Zephyrisle\FlarumZaiBot\Listener\RecordContextEvent::class),
        ]),

    // fof/move-posts: 记录帖子移动事件到上下文
    (new Extend\Conditional())
        ->whenExtensionEnabled('fof-move-posts', fn () => [
            (new Extend\Event())
                ->listen(\FoF\MovePosts\Event\PostsMoved::class, \Zephyrisle\FlarumZaiBot\Listener\RecordContextEvent::class),
        ]),

    // fof/split: 记录讨论拆分事件到上下文
    (new Extend\Conditional())
        ->whenExtensionEnabled('fof-split', fn () => [
            (new Extend\Event())
                ->listen(\FoF\Split\Events\DiscussionWasSplit::class, \Zephyrisle\FlarumZaiBot\Listener\RecordContextEvent::class),
        ]),

    // fof/moderator-warnings: 记录警告事件到上下文
    (new Extend\Conditional())
        ->whenExtensionEnabled('fof-moderator-warnings', fn () => [
            (new Extend\Event())
                ->listen(\FoF\ModeratorWarnings\Events\WarningIssued::class, \Zephyrisle\FlarumZaiBot\Listener\RecordContextEvent::class),
        ]),

    (new Extend\Settings())
        ->default('flarum-zai-bot.bot_display_name', 'Yuki')
        ->default('flarum-zai-bot.timezone', 'Asia/Shanghai')
        ->default('flarum-zai-bot.openweather_city', 'Beijing')
        ->default('flarum-zai-bot.jina_optimization_mode', false)
        ->default('flarum-zai-bot.jina_use_builtin_proxy', false)
        ->default('flarum-zai-bot.message_reply_enabled', false)
        ->default('flarum-zai-bot.random_reply_chance', 0)
        ->default('flarum-zai-bot.reply_cooldown', 30)
        ->default('flarum-zai-bot.embedding_api_url', \Zephyrisle\FlarumZaiBot\Service\EmbeddingService::DEFAULT_API_URL)
        ->default('flarum-zai-bot.embedding_model', \Zephyrisle\FlarumZaiBot\Service\EmbeddingService::DEFAULT_MODEL)
        // 智能唤醒（提及规则 / 相关性 / 专业答疑 / 无聊唤醒）
        ->default('flarum-zai-bot.wake_mention_rules_enabled', false)
        ->default('flarum-zai-bot.wake_relevance_enabled', false)
        ->default('flarum-zai-bot.wake_expert_enabled', false)
        ->default('flarum-zai-bot.wake_boredom_enabled', false)
        // 请求编排：硬等待消息合并
        ->default('flarum-zai-bot.wake_merge_seconds', 0)
        ->default('flarum-zai-bot.wake_merge_max', 5)
        ->default('flarum-zai-bot.wake_merge_require_wake', false)
        // 媒体解析
        ->default('flarum-zai-bot.media_link_parse_enabled', false)
        ->default('flarum-zai-bot.media_link_timeout', 8)
        ->default('flarum-zai-bot.media_link_max_bytes', 524288)
        ->default('flarum-zai-bot.media_link_max_links', 2)
        ->default('flarum-zai-bot.media_file_parse_enabled', false)
        ->default('flarum-zai-bot.media_image_classify_enabled', true)
        // 上下文注入（注入时机 / 事件记录 / 格式与截断）
        ->default('flarum-zai-bot.ctx_inject_timing', 'proactive')
        ->default('flarum-zai-bot.ctx_event_record_enabled', true)
        ->default('flarum-zai-bot.ctx_format', 'concise')
        ->default('flarum-zai-bot.ctx_entry_max_chars', 200)
        ->default('flarum-zai-bot.ctx_max_events', 10)
        // 记忆系统（记忆原子 + 混合检索）
        ->default('flarum-zai-bot.memory_hybrid_vector_weight', 60)
        ->default('flarum-zai-bot.memory_decay_days', 30)
        // 细致好感度系统：黑名单熔断阈值（好感度 ≤ 该值自动拉黑，0 表示禁用）
        ->default('flarum-zai-bot.affinity_blacklist_threshold', 0)
        // 关系网与表达学习开关
        ->default('flarum-zai-bot.relation_network_enabled', true)
        ->default('flarum-zai-bot.expression_learning_enabled', true)
        // Agnes AI 图片/视频生成 API Key
        ->default('flarum-zai-bot.agnes_api_key', ''),

    (new Extend\Model(\Zephyrisle\FlarumZaiBot\Model\ContextEvent::class)),

    (new Extend\Model(\Zephyrisle\FlarumZaiBot\Model\BotAffinity::class)),

    (new Extend\Model(\Zephyrisle\FlarumZaiBot\Model\UserPortrait::class)),

    (new Extend\Model(\Zephyrisle\FlarumZaiBot\Model\BotRelation::class)),

    (new Extend\Model(\Zephyrisle\FlarumZaiBot\Model\BotExpression::class)),

    // flarum/gdpr: 将机器人数据纳入用户数据导出
    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new \Flarum\Gdpr\Extend\UserData())
                ->addType(\Zephyrisle\FlarumZaiBot\Service\GdprDataProvider::class),
        ]),

    (new Extend\Locales(__DIR__ . '/locale')),

    (new Extend\Routes('api'))
        ->get('/zai-bot/affinities', 'zai-bot.affinities', \Zephyrisle\FlarumZaiBot\Api\Controller\ListAffinitiesController::class)
        ->patch('/zai-bot/affinities/{userId}', 'zai-bot.affinities.update', \Zephyrisle\FlarumZaiBot\Api\Controller\UpdateAffinityController::class)
        ->get('/zai-bot/memories', 'zai-bot.memories', \Zephyrisle\FlarumZaiBot\Api\Controller\ListMemoriesController::class)
        ->patch('/zai-bot/memories/{id}', 'zai-bot.memories.update', \Zephyrisle\FlarumZaiBot\Api\Controller\UpdateMemoryController::class)
        ->get('/zai-bot/relations', 'zai-bot.relations', \Zephyrisle\FlarumZaiBot\Api\Controller\ListRelationsController::class)
        ->patch('/zai-bot/relations/{userId}', 'zai-bot.relations.update', \Zephyrisle\FlarumZaiBot\Api\Controller\UpdateRelationController::class)
        ->get('/zai-bot/expressions', 'zai-bot.expressions', \Zephyrisle\FlarumZaiBot\Api\Controller\ListExpressionsController::class)
        ->patch('/zai-bot/expressions/{id}', 'zai-bot.expressions.update', \Zephyrisle\FlarumZaiBot\Api\Controller\UpdateExpressionController::class)
        ->post('/zai-bot/test-api', 'zai-bot.test-api', \Zephyrisle\FlarumZaiBot\Api\Controller\TestApiController::class)
        ->get('/zai-bot/jina-proxy', 'zai-bot.jina-proxy', \Zephyrisle\FlarumZaiBot\Api\Controller\JinaProxyController::class)
        // 免鉴权图片代理：供 AI 模型视觉 API 拉取 fof/upload 的私有图片（见 ImageExtractor / VisionImageController）
        ->get('/zai-bot/vision-image/{uuid}', 'zai-bot.vision-image', \Zephyrisle\FlarumZaiBot\Api\Controller\VisionImageController::class)
        // Agnes AI API 代理：图片/视频生成（需管理员权限）
        ->any('/zai-bot/agnes/{path:.+}', 'zai-bot.agnes-proxy', \Zephyrisle\FlarumZaiBot\Api\Controller\AgnesProxyController::class)
        // Jina API Key 测试
        ->post('/zai-bot/jina/test-key', 'zai-bot.jina.test-key', \Zephyrisle\FlarumZaiBot\Api\Controller\TestJinaKeyController::class),
];
