<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Zephyrisle\FlarumZaiBot\Model\BotAffinity;
use Zephyrisle\FlarumZaiBot\Service\AIService;
use Zephyrisle\FlarumZaiBot\Service\ProviderService;
use Zephyrisle\FlarumZaiBot\Service\Tool\ToolInterface;
use Zephyrisle\FlarumZaiBot\Tests\Unit\Concerns\ResetsBotAffinities;

class AIServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use ResetsBotAffinities;

    /** @var SettingsRepositoryInterface&Mockery\MockInterface */
    protected $settings;

    /** @var Client&Mockery\MockInterface */
    protected $client;

    protected AIService $ai;

    protected function setUp(): void
    {
        $this->settings = Mockery::mock(SettingsRepositoryInterface::class);
        $this->client = Mockery::mock(Client::class);
        $this->ai = new AIService($this->settings, $this->client, new ProviderService($this->settings));

        $this->resetBotAffinities();
    }

    /**
     * Stub every setting read that AIService performs during a normal generateReply call.
     */
    protected function stubCommonSettings(): void
    {
        $this->stubSettings([
            'flarum-zai-bot.providers' => json_encode([
                ['name' => 'Default', 'api_url' => 'https://api.openai.com/v1', 'api_keys' => 'key-1', 'model' => 'gpt-4o-mini'],
            ]),
        ]);
    }

    /**
     * 基于通用设置构造 settings mock，并允许按测试覆盖指定键。
     */
    protected function stubSettings(array $overrides = []): void
    {
        $values = array_merge([
            'flarum-zai-bot.last_llm_key_index' => -1,
            'flarum-zai-bot.timezone' => 'Asia/Shanghai',
            'flarum-zai-bot.system_prompt' => 'You are a friendly community forum assistant. Keep responses concise and helpful.',
            'flarum-zai-bot.bot_display_name' => 'Yuki',
            'flarum-zai-bot.openweather_key' => null,
        ], $overrides);

        $this->settings->shouldReceive('get')->andReturnUsing(function (string $key, mixed $default = null) use ($values) {
            return array_key_exists($key, $values) ? $values[$key] : $default;
        });

        $this->settings->shouldReceive('set')->byDefault()->andReturnNull();
    }

    public function testGenerateReplyReturnsContentFromApi(): void
    {
        $this->stubCommonSettings();

        $this->client->shouldReceive('post')
            ->once()
            ->andReturn(new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => '你好，世界！']]],
            ])));

        $reply = $this->ai->generateReply('你好');

        $this->assertSame('你好，世界！', $reply);
    }

    public function testGenerateReplyFailsOverToNextApiKey(): void
    {
        $this->stubSettings([
            'flarum-zai-bot.providers' => json_encode([
                ['name' => 'Default', 'api_url' => 'https://api.openai.com/v1', 'api_keys' => 'key-1,key-2', 'model' => 'gpt-4o-mini'],
            ]),
        ]);

        // First key: connection failure
        $this->client->shouldReceive('post')
            ->once()
            ->andThrow(new \Exception('connection refused'));

        // Second key: success
        $this->client->shouldReceive('post')
            ->once()
            ->andReturn(new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => 'recovered']]],
            ])));

        // The winning key's index (1) is remembered for round-robin rotation
        $this->settings->shouldReceive('set')
            ->with('flarum-zai-bot.last_llm_key_index', '1')
            ->once();

        $reply = $this->ai->generateReply('hi');

        $this->assertSame('recovered', $reply);
    }

    public function testGenerateReplyRotatesKeysRoundRobin(): void
    {
        $this->stubSettings([
            'flarum-zai-bot.providers' => json_encode([
                ['name' => 'Default', 'api_url' => 'https://api.openai.com/v1', 'api_keys' => 'key-1,key-2', 'model' => 'gpt-4o-mini'],
            ]),
            // 上次成功的是 key-2（索引 1），本次应从 key-1 开始
            'flarum-zai-bot.last_llm_key_index' => 1,
        ]);

        // 第一把钥匙（key-1）失败，第二把（key-2）成功
        $this->client->shouldReceive('post')
            ->once()
            ->andThrow(new \Exception('rate limited'));

        $this->client->shouldReceive('post')
            ->once()
            ->andReturn(new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => 'rotated']]],
            ])));

        $this->settings->shouldReceive('set')
            ->with('flarum-zai-bot.last_llm_key_index', '1')
            ->once();

        $this->assertSame('rotated', $this->ai->generateReply('hi'));
    }

    public function testGenerateReplyBuildsRichContextMessages(): void
    {
        $this->stubCommonSettings();

        $context = [
            'channel' => 'forum',
            'user_id' => 5,
            'username' => 'tester',
            'display_name' => '小测试',
            'is_verified' => true,
            'affinity_score' => 60,
            'portrait_summary' => '对编程很感兴趣',
            'memories' => [['created_at' => '2026-01-01 10:00', 'content' => '喜欢围棋']],
            'current_post_id' => 123,
            'discussion_title' => '测试讨论帖',
            'conversation_history' => [['post_id' => 1, 'author' => '甲', 'content' => '你好']],
        ];

        $this->client->shouldReceive('post')
            ->once()
            ->with(
                Mockery::on(fn ($uri) => is_string($uri)),
                Mockery::on(function (array $options) {
                    $messages = $options['json']['messages'] ?? [];
                    $all = json_encode($messages, JSON_UNESCAPED_UNICODE);

                    return str_contains($all, '用户画像：对编程很感兴趣')
                        && str_contains($all, '相关记忆')
                        && str_contains($all, '喜欢围棋')
                        && str_contains($all, '当前帖子ID：123')
                        && str_contains($all, '当前讨论主题：测试讨论帖')
                        && str_contains($all, '昵称：小测试')
                        && str_contains($all, '认证状态：已认证')
                        && str_contains($all, '对话历史')
                        && str_contains($all, '好感度：60分');
                })
            )
            ->andReturn(new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => 'context ok']]],
            ])));

        $reply = $this->ai->generateReply('测试一下', $context);

        $this->assertSame('context ok', $reply);
    }

    public function testGenerateReplyInjectsSkipDecisionWhenRepliedRecently(): void
    {
        $this->stubCommonSettings();

        $context = [
            'channel' => 'forum',
            'replied_recently' => true,
            'replied_recently_seconds_ago' => 20,
            'last_bot_reply_excerpt' => '这是机器人上次的回复内容',
        ];

        $this->client->shouldReceive('post')
            ->once()
            ->with(
                Mockery::on(fn ($uri) => is_string($uri)),
                Mockery::on(function (array $options) {
                    $messages = $options['json']['messages'] ?? [];
                    $all = json_encode($messages, JSON_UNESCAPED_UNICODE);

                    return str_contains($all, '20秒前刚在这个讨论中回复过')
                        && str_contains($all, '这是机器人上次的回复内容')
                        && str_contains($all, '[ZAI_SKIP]');
                })
            )
            ->andReturn(new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => 'ok']]],
            ])));

        $reply = $this->ai->generateReply('新帖子内容', $context);

        $this->assertSame('ok', $reply);
    }

    public function testGenerateReplySkipsSkipInstructionForMessagesChannel(): void
    {
        $this->stubCommonSettings();

        // 私信频道即使刚回复过，也不注入跳过指令（私信始终回复）
        $context = [
            'channel' => 'message',
            'replied_recently' => true,
            'replied_recently_seconds_ago' => 5,
            'last_bot_reply_excerpt' => '上次回复',
        ];

        $this->client->shouldReceive('post')
            ->once()
            ->with(
                Mockery::on(fn ($uri) => is_string($uri)),
                Mockery::on(function (array $options) {
                    $messages = $options['json']['messages'] ?? [];
                    return !str_contains(json_encode($messages, JSON_UNESCAPED_UNICODE), '[ZAI_SKIP]');
                })
            )
            ->andReturn(new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => 'msg ok']]],
            ])));

        $reply = $this->ai->generateReply('你好', $context);

        $this->assertSame('msg ok', $reply);
    }

    public function testGenerateReplyFailsOverAcrossProviders(): void
    {
        $this->stubSettings([
            'flarum-zai-bot.providers' => json_encode([
                ['name' => 'Down', 'api_url' => 'https://down.example/v1', 'api_keys' => 'k-down', 'model' => 'down-model'],
                ['name' => 'Up', 'api_url' => 'https://up.example/v1', 'api_keys' => 'k-up', 'model' => 'up-model'],
            ]),
        ]);

        // 供应商 Down 失败 → 自动回退到供应商 Up，使用 Up 自己的 URL 与模型
        $this->client->shouldReceive('post')
            ->once()
            ->with(
                Mockery::on(fn ($uri) => str_starts_with((string) $uri, 'https://down.example/v1/chat/completions')),
                Mockery::on(fn (array $options) => ($options['json']['model'] ?? '') === 'down-model')
            )
            ->andThrow(new \Exception('provider down'));

        $this->client->shouldReceive('post')
            ->once()
            ->with(
                Mockery::on(fn ($uri) => str_starts_with((string) $uri, 'https://up.example/v1/chat/completions')),
                Mockery::on(fn (array $options) => ($options['json']['model'] ?? '') === 'up-model')
            )
            ->andReturn(new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => 'from-up']]],
            ])));

        // 成功的端点（Up，索引 1）被记录，供下次轮询
        $this->settings->shouldReceive('set')
            ->with('flarum-zai-bot.last_llm_key_index', '1')
            ->once();

        $this->assertSame('from-up', $this->ai->generateReply('hi'));
    }

    public function testGenerateReplyReturnsNullWhenAllKeysFail(): void
    {
        $this->stubCommonSettings();

        // api_keys = 'key-1' → only one key is attempted
        $this->client->shouldReceive('post')
            ->once()
            ->andThrow(new \Exception('connection refused'));

        $this->assertNull($this->ai->generateReply('hi'));
    }

    public function testGenerateReplyExecutesToolCallsAndReturnsFinalContent(): void
    {
        $this->stubCommonSettings();

        $tool = Mockery::mock(ToolInterface::class);
        $tool->shouldReceive('getName')->andReturn('fake_tool');
        $tool->shouldReceive('getDescription')->andReturn('A fake tool');
        $tool->shouldReceive('getParameters')->andReturn(['type' => 'object', 'properties' => []]);
        $tool->shouldReceive('execute')
            ->once()
            ->with(['x' => 1])
            ->andReturn('tool-result');

        // Round 1: the model asks to call the tool
        $this->client->shouldReceive('post')
            ->once()
            ->andReturn(new Response(200, [], json_encode([
                'choices' => [[
                    'message' => [
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => ['name' => 'fake_tool', 'arguments' => '{"x": 1}'],
                        ]],
                    ],
                ]],
            ])));

        // Round 2: final answer after the tool result
        $this->client->shouldReceive('post')
            ->once()
            ->andReturn(new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => 'final answer']]],
            ])));

        $reply = $this->ai->generateReply('please use the tool', [], [$tool]);

        $this->assertSame('final answer', $reply);
    }

    public function testGenerateReplySendsImagesToVisionEndpoint(): void
    {
        $this->stubSettings([
            'flarum-zai-bot.providers' => json_encode([
                ['name' => 'Vision', 'api_url' => 'https://api.openai.com/v1', 'api_keys' => 'key-1', 'model' => 'gpt-4o', 'vision' => true],
            ]),
        ]);

        $this->client->shouldReceive('post')
            ->once()
            ->with(
                Mockery::on(fn ($uri) => is_string($uri)),
                Mockery::on(function (array $options) {
                    $messages = $options['json']['messages'] ?? [];
                    $user = end($messages);

                    if (!is_array($user['content'] ?? null)) {
                        return false;
                    }

                    $types = array_column($user['content'], 'type');
                    $hasText = in_array('text', $types, true) && str_contains($user['content'][0]['text'], '看图');
                    $images = array_values(array_filter($user['content'], fn ($p) => ($p['type'] ?? '') === 'image_url'));

                    return $hasText
                        && count($images) === 2
                        && $images[0]['image_url']['url'] === 'https://example.com/a.png'
                        && $images[1]['image_url']['url'] === 'https://example.com/b.jpg';
                })
            )
            ->andReturn(new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => '我看到了图片！']]],
            ])));

        $reply = $this->ai->generateReply('看看这张图', [
            'images' => ['https://example.com/a.png', 'https://example.com/b.jpg', '/relative.png'],
        ]);

        $this->assertSame('我看到了图片！', $reply);
    }

    public function testGenerateReplyDropsImagesForNonVisionEndpoint(): void
    {
        $this->stubCommonSettings();

        $this->client->shouldReceive('post')
            ->once()
            ->with(
                Mockery::on(fn ($uri) => is_string($uri)),
                Mockery::on(function (array $options) {
                    $messages = $options['json']['messages'] ?? [];
                    $user = end($messages);

                    // 不支持的端点收到纯文本，图片被丢弃
                    return is_string($user['content'] ?? null)
                        && str_contains($user['content'], '看看这张图')
                        && !str_contains(json_encode($messages, JSON_UNESCAPED_UNICODE), 'image_url');
                })
            )
            ->andReturn(new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => '文字回复']]],
            ])));

        $reply = $this->ai->generateReply('看看这张图', [
            'images' => ['https://example.com/a.png'],
            'history_images' => [
                ['url' => 'https://example.com/hist.png', 'author' => '小明', 'label' => '帖子 #3（小明）'],
            ],
        ]);

        $this->assertSame('文字回复', $reply);
    }

    public function testGenerateReplySendsHistoryImagesWithCaptions(): void
    {
        $this->stubSettings([
            'flarum-zai-bot.providers' => json_encode([
                ['name' => 'Vision', 'api_url' => 'https://api.openai.com/v1', 'api_keys' => 'key-1', 'model' => 'gpt-4o', 'vision' => true],
            ]),
        ]);

        $this->client->shouldReceive('post')
            ->once()
            ->with(
                Mockery::on(fn ($uri) => is_string($uri)),
                Mockery::on(function (array $options) {
                    $messages = $options['json']['messages'] ?? [];
                    $user = end($messages);
                    $parts = $user['content'] ?? [];

                    if (!is_array($parts) || ($parts[0]['type'] ?? '') !== 'text') {
                        return false;
                    }

                    // 第一块说明当前消息与历史图片的总数
                    if (!str_contains($parts[0]['text'], '本次对话共附带 3 张图片')) {
                        return false;
                    }

                    $lastCurrentImageIndex = null;
                    $historyIndex = null;
                    foreach ($parts as $i => $part) {
                        if (($part['type'] ?? '') === 'image_url'
                            && ($part['image_url']['url'] ?? '') === 'https://example.com/current.png') {
                            $lastCurrentImageIndex = $i;
                        }
                        if (($part['type'] ?? '') === 'text'
                            && str_contains($part['text'] ?? '', '对话历史中的图片')
                            && $historyIndex === null) {
                            $historyIndex = $i;
                        }
                    }

                    // 历史图片带说明文字、排在当前图片之后，且多张历史图片依次排列
                    return $historyIndex !== null
                        && $lastCurrentImageIndex !== null
                        && $historyIndex > $lastCurrentImageIndex
                        && ($parts[$historyIndex + 1]['type'] ?? '') === 'image_url'
                        && ($parts[$historyIndex + 1]['image_url']['url'] ?? '') === 'https://example.com/history-1.png'
                        && str_contains($parts[$historyIndex]['text'], '帖子 #5（小明）')
                        && str_contains($parts[$historyIndex + 2]['text'] ?? '', '帖子 #6（小红）')
                        && ($parts[$historyIndex + 3]['image_url']['url'] ?? '') === 'https://example.com/history-2.png';
                })
            )
            ->andReturn(new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => '结合历史图片回答']]],
            ])));

        $reply = $this->ai->generateReply('继续聊这张图', [
            'images' => ['https://example.com/current.png'],
            'history_images' => [
                ['url' => 'https://example.com/history-1.png', 'author' => '小明', 'label' => '帖子 #5（小明）'],
                ['url' => 'https://example.com/history-2.png', 'author' => '小红', 'label' => '帖子 #6（小红）'],
            ],
        ]);

        $this->assertSame('结合历史图片回答', $reply);
    }

    public function testGenerateReplyCapsHistoryImagesToMostRecent(): void
    {
        $this->stubSettings([
            'flarum-zai-bot.providers' => json_encode([
                ['name' => 'Vision', 'api_url' => 'https://api.openai.com/v1', 'api_keys' => 'key-1', 'model' => 'gpt-4o', 'vision' => true],
            ]),
        ]);

        $this->client->shouldReceive('post')
            ->once()
            ->with(
                Mockery::on(fn ($uri) => is_string($uri)),
                Mockery::on(function (array $options) {
                    $messages = $options['json']['messages'] ?? [];
                    $all = json_encode($messages, JSON_UNESCAPED_UNICODE);

                    // 只保留最近的 3 张历史图片（按时间正序传入，取最后 3 个）。
                    // 用文件名匹配：json_encode 会把 / 转义为 \\/，直接匹配完整 URL 会失败。
                    return str_contains($all, 'h-3.png')
                        && str_contains($all, 'h-4.png')
                        && str_contains($all, 'h-5.png')
                        && !str_contains($all, 'h-1.png')
                        && !str_contains($all, 'h-2.png')
                        && str_contains($all, 'image_url');
                })
            )
            ->andReturn(new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => 'ok']]],
            ])));

        $historyImages = [];
        for ($i = 1; $i <= 5; $i++) {
            $historyImages[] = ['url' => "https://example.com/h-{$i}.png", 'author' => '用户', 'label' => "帖子 #{$i}"];
        }

        $this->ai->generateReply('hi', ['history_images' => $historyImages]);
    }

    public function testGenerateReplyKeepsImagesAcrossToolCallRounds(): void
    {
        $this->stubSettings([
            'flarum-zai-bot.providers' => json_encode([
                ['name' => 'Vision', 'api_url' => 'https://api.openai.com/v1', 'api_keys' => 'key-1', 'model' => 'gpt-4o', 'vision' => true],
            ]),
        ]);

        $tool = Mockery::mock(ToolInterface::class);
        $tool->shouldReceive('getName')->andReturn('fake_tool');
        $tool->shouldReceive('getDescription')->andReturn('A fake tool');
        $tool->shouldReceive('getParameters')->andReturn(['type' => 'object', 'properties' => []]);
        $tool->shouldReceive('execute')->once()->andReturn('tool-result');

        $hasImageContent = function (array $options): bool {
            $messages = $options['json']['messages'] ?? [];
            $hasCurrent = false;
            $hasHistory = false;
            foreach ($messages as $m) {
                if (($m['role'] ?? '') === 'user' && is_array($m['content'] ?? null)) {
                    foreach ($m['content'] as $part) {
                        if (($part['type'] ?? '') !== 'image_url') {
                            continue;
                        }
                        if (($part['image_url']['url'] ?? '') === 'https://example.com/a.png') {
                            $hasCurrent = true;
                        }
                        if (($part['image_url']['url'] ?? '') === 'https://example.com/hist.png') {
                            $hasHistory = true;
                        }
                    }
                }
            }
            return $hasCurrent && $hasHistory;
        };

        // 第一轮：带图片的请求，模型要求调用工具
        $this->client->shouldReceive('post')
            ->once()
            ->with(Mockery::on(fn ($uri) => is_string($uri)), Mockery::on($hasImageContent))
            ->andReturn(new Response(200, [], json_encode([
                'choices' => [[
                    'message' => [
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => ['name' => 'fake_tool', 'arguments' => '{}'],
                        ]],
                    ],
                ]],
            ])));

        // 第二轮（工具结果之后）：图片仍在请求中
        $this->client->shouldReceive('post')
            ->once()
            ->with(Mockery::on(fn ($uri) => is_string($uri)), Mockery::on($hasImageContent))
            ->andReturn(new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => '看完图片后的回答']]],
            ])));

        $reply = $this->ai->generateReply('描述这张图', [
            'images' => ['https://example.com/a.png'],
            'history_images' => [
                ['url' => 'https://example.com/hist.png', 'author' => '小明', 'label' => '帖子 #3（小明）'],
            ],
        ], [$tool]);

        $this->assertSame('看完图片后的回答', $reply);
    }

    public function testGenerateReplyInjectsMediaContext(): void
    {
        $this->stubCommonSettings();

        $this->client->shouldReceive('post')
            ->once()
            ->with(
                Mockery::on(fn ($uri) => is_string($uri)),
                Mockery::on(function (array $options) {
                    $messages = $options['json']['messages'] ?? [];
                    return str_contains(json_encode($messages, JSON_UNESCAPED_UNICODE), '帖子中链接的内容摘要：\n- Test Page：A summary');
                })
            )
            ->andReturn(new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => 'ok']]],
            ])));

        $reply = $this->ai->generateReply('hi', [
            'media_context' => "帖子中链接的内容摘要：\n- Test Page：A summary",
        ]);

        $this->assertSame('ok', $reply);
    }

    public function testGenerateReplyInjectsContextBlock(): void
    {
        $this->stubCommonSettings();

        $this->client->shouldReceive('post')
            ->once()
            ->with(
                Mockery::on(fn ($uri) => is_string($uri)),
                Mockery::on(function (array $options) {
                    $messages = $options['json']['messages'] ?? [];
                    return str_contains(json_encode($messages, JSON_UNESCAPED_UNICODE), '【讨论上下文】')
                        && str_contains(json_encode($messages, JSON_UNESCAPED_UNICODE), '讨论标题：测试讨论')
                        && str_contains(json_encode($messages, JSON_UNESCAPED_UNICODE), '近期事件');
                })
            )
            ->andReturn(new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => 'ok']]],
            ])));

        $reply = $this->ai->generateReply('hi', [
            'injected_context' => "【讨论上下文】\n- 讨论标题：测试讨论\n\n【近期事件】\n- 帖子 #45 被隐藏（撤回）",
        ]);

        $this->assertSame('ok', $reply);
    }

    public function testGenerateReplyLabelsGifAndStickerImages(): void
    {
        $this->stubSettings([
            'flarum-zai-bot.providers' => json_encode([
                ['name' => 'Vision', 'api_url' => 'https://api.openai.com/v1', 'api_keys' => 'key-1', 'model' => 'gpt-4o', 'vision' => true],
            ]),
        ]);

        $this->client->shouldReceive('post')
            ->once()
            ->with(
                Mockery::on(fn ($uri) => is_string($uri)),
                Mockery::on(function (array $options) {
                    $messages = $options['json']['messages'] ?? [];
                    $user = end($messages);
                    $parts = $user['content'] ?? [];

                    // 找到 GIF 与贴纸的标注文字，且标注紧跟对应图片
                    $gifIndex = null;
                    $stickerIndex = null;
                    foreach ($parts as $i => $part) {
                        if (($part['type'] ?? '') === 'text' && ($part['text'] ?? '') === '（GIF 动图）') {
                            $gifIndex = $i;
                        }
                        if (($part['type'] ?? '') === 'text' && ($part['text'] ?? '') === '（贴纸）') {
                            $stickerIndex = $i;
                        }
                    }

                    return $gifIndex !== null
                        && $stickerIndex !== null
                        && ($parts[$gifIndex + 1]['type'] ?? '') === 'image_url'
                        && ($parts[$gifIndex + 1]['image_url']['url'] ?? '') === 'https://example.com/anim.gif'
                        && ($parts[$stickerIndex + 1]['image_url']['url'] ?? '') === 'https://example.com/sticker/1.png';
                })
            )
            ->andReturn(new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => 'ok']]],
            ])));

        $this->ai->generateReply('hi', [
            'images' => [
                'https://example.com/anim.gif',
                'https://example.com/sticker/1.png',
                'https://example.com/photo.jpg',
            ],
        ]);
    }

    public function testParseSecretEvalUpdatesAffinityAndStripsBlock(): void
    {
        $reply = "今天聊得很开心！\n[Favour: 45, Attitude: 友善热情, Relationship: 普通朋友]";

        $result = $this->ai->parseSecretEval($reply, 100);

        $this->assertSame('今天聊得很开心！', $result);

        $affinity = BotAffinity::where('user_id', 100)->first();
        $this->assertNotNull($affinity);
        $this->assertSame(45, (int) $affinity->total_score);
        $this->assertSame(1, (int) $affinity->interaction_count);
    }

    public function testParseSecretEvalAcceptsFullWidthPunctuation(): void
    {
        // 容忍 AI 输出全角冒号/逗号的格式变体
        $reply = "好的！[Favour：30，Attitude：温和友善，Relationship：普通朋友]";

        $result = $this->ai->parseSecretEval($reply, 200);

        $this->assertSame('好的！', $result);

        $affinity = BotAffinity::where('user_id', 200)->first();
        $this->assertNotNull($affinity);
        $this->assertSame(30, (int) $affinity->total_score);
    }

    public function testParseSecretEvalClampsScoreToBounds(): void
    {
        $this->ai->parseSecretEval('[Favour: 500, Attitude: x, Relationship: y]', 101);

        $affinity = BotAffinity::where('user_id', 101)->first();
        $this->assertNotNull($affinity);
        $this->assertSame(100, (int) $affinity->total_score);
    }

    public function testParseSecretEvalLeavesReplyUntouchedWithoutBlock(): void
    {
        $reply = '普通回复，没有秘密评估块';

        $result = $this->ai->parseSecretEval($reply, 102);

        $this->assertSame($reply, $result);
        $this->assertNull(BotAffinity::where('user_id', 102)->first());
    }
}
