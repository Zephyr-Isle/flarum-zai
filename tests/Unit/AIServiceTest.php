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
        $this->settings->shouldReceive('get')->andReturnUsing(function (string $key, mixed $default = null) {
            return match ($key) {
                'flarum-zai-bot.providers' => '',
                'flarum-zai-bot.api_url' => 'https://api.openai.com/v1',
                'flarum-zai-bot.model' => 'gpt-3.5-turbo',
                'flarum-zai-bot.api_keys' => 'key-1',
                'flarum-zai-bot.last_llm_key_index' => -1,
                'flarum-zai-bot.timezone' => 'Asia/Shanghai',
                'flarum-zai-bot.system_prompt' => 'You are a friendly community forum assistant. Keep responses concise and helpful.',
                'flarum-zai-bot.bot_display_name' => 'Yuki',
                'flarum-zai-bot.openweather_key' => null,
                default => $default,
            };
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
        $this->settings->shouldReceive('get')->andReturnUsing(function (string $key, mixed $default = null) {
            return match ($key) {
                'flarum-zai-bot.providers' => '',
                'flarum-zai-bot.api_url' => 'https://api.openai.com/v1',
                'flarum-zai-bot.model' => 'gpt-3.5-turbo',
                'flarum-zai-bot.api_keys' => 'key-1,key-2',
                'flarum-zai-bot.last_llm_key_index' => -1,
                'flarum-zai-bot.timezone' => 'Asia/Shanghai',
                'flarum-zai-bot.system_prompt' => 'You are a friendly community forum assistant. Keep responses concise and helpful.',
                'flarum-zai-bot.bot_display_name' => 'Yuki',
                'flarum-zai-bot.openweather_key' => null,
                default => $default,
            };
        });

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
        $this->settings->shouldReceive('get')->andReturnUsing(function (string $key, mixed $default = null) {
            return match ($key) {
                'flarum-zai-bot.providers' => '',
                'flarum-zai-bot.api_url' => 'https://api.openai.com/v1',
                'flarum-zai-bot.model' => 'gpt-3.5-turbo',
                'flarum-zai-bot.api_keys' => 'key-1,key-2',
                // 上次成功的是 key-2（索引 1），本次应从 key-1 开始
                'flarum-zai-bot.last_llm_key_index' => 1,
                'flarum-zai-bot.timezone' => 'Asia/Shanghai',
                'flarum-zai-bot.system_prompt' => 'You are a friendly community forum assistant. Keep responses concise and helpful.',
                'flarum-zai-bot.bot_display_name' => 'Yuki',
                'flarum-zai-bot.openweather_key' => null,
                default => $default,
            };
        });

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
        $this->settings->shouldReceive('get')->andReturnUsing(function (string $key, mixed $default = null) {
            return match ($key) {
                'flarum-zai-bot.providers' => json_encode([
                    ['name' => 'Down', 'api_url' => 'https://down.example/v1', 'api_keys' => 'k-down', 'model' => 'down-model'],
                    ['name' => 'Up', 'api_url' => 'https://up.example/v1', 'api_keys' => 'k-up', 'model' => 'up-model'],
                ]),
                'flarum-zai-bot.last_llm_key_index' => -1,
                'flarum-zai-bot.timezone' => 'Asia/Shanghai',
                'flarum-zai-bot.system_prompt' => 'You are a friendly community forum assistant. Keep responses concise and helpful.',
                'flarum-zai-bot.bot_display_name' => 'Yuki',
                'flarum-zai-bot.openweather_key' => null,
                default => $default,
            };
        });
        $this->settings->shouldReceive('set')->byDefault()->andReturnNull();

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
