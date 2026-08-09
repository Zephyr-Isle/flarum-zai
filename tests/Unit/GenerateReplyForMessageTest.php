<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use Flarum\Messages\Dialog;
use Flarum\Messages\DialogMessage;
use Flarum\Messages\DialogMessage\Event\Created;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Capsule\Manager as Capsule;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Zephyrisle\FlarumZaiBot\Job\GenerateReplyForMessage;
use Zephyrisle\FlarumZaiBot\Model\BotAffinity;
use Zephyrisle\FlarumZaiBot\Service\AIService;
use Zephyrisle\FlarumZaiBot\Service\MemoryService;
use Zephyrisle\FlarumZaiBot\Service\PortraitService;

/**
 * GenerateReplyForMessage 私信回复任务测试。
 *
 * 设计说明：这里使用真实的 DialogMessage/Dialog/Created 类（flarum/messages 以
 * require-dev 安装），而不是 Mockery alias-mock —— 真实类才有真实的 Created 构造器
 * 语义和模型关系行为。为让这些类在无 Flarum 容器时工作，本测试类会注入静态依赖
 * （Formatter / DisplayNameDriver）并设置全局 Container。这些静态状态在 tearDown 中
 * 不重置（类没有 unsetter），仅影响本测试类，但后续新增读取 display_name 或
 * DialogMessage.content 的测试需注意这一点。
 */
class GenerateReplyForMessageTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const HUMAN_ID = 99;
    private const BOT_ID = 42;

    protected Container $container;

    protected function setUp(): void
    {
        if (!class_exists(DialogMessage::class)) {
            $this->markTestSkipped('flarum/messages is not installed.');
        }

        // 任务的 resolve() 走 Flarum 的全局辅助函数，它读取 Container::getInstance()。
        $this->container = new Container();
        Container::setInstance($this->container);

        // DialogMessage 的 content 访问器/修改器依赖 Flarum\Formatter\Formatter，
        // 注入一个 passthrough mock（内容原样存取，模拟未启用格式化的情况）。
        $formatter = Mockery::mock(\Flarum\Formatter\Formatter::class);
        $formatter->shouldReceive('parse')->andReturnUsing(fn ($value) => $value);
        $formatter->shouldReceive('unparse')->andReturnUsing(fn ($value) => $value);
        DialogMessage::setFormatter($formatter);

        // User::display_name 的读取走 getDisplayNameAttribute，依赖容器注入的驱动。
        // 注入一个直接读取 display_name 属性的驱动（不触发递归访问器）。
        User::setDisplayNameDriver(new class implements \Flarum\User\DisplayName\DriverInterface {
            public function displayName(User $user): string
            {
                $attrs = $user->getAttributes();

                return $attrs['display_name'] ?? $attrs['username'] ?? 'unknown';
            }
        });

        foreach (['dialog_messages', 'dialog_user', 'dialogs', 'bot_affinities', 'users', 'posts', 'groups', 'group_user'] as $table) {
            Capsule::table($table)->truncate();
        }
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
    }

    /**
     * 匿名子类覆盖 getBotUser，避免真实查询（users/groups 表在测试环境不完整）。
     */
    protected function job(int $messageId): GenerateReplyForMessage
    {
        return new class ($messageId) extends GenerateReplyForMessage {
            public ?User $botUser = null;

            protected function getBotUser(string $botUsername): User
            {
                return $this->botUser;
            }
        };
    }

    protected function botUser(): User
    {
        $bot = Mockery::mock(User::class)->makePartial();
        $bot->id = self::BOT_ID;

        return $bot;
    }

    protected function settings(bool $enabled = true): SettingsRepositoryInterface
    {
        $settings = Mockery::mock(SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->with('flarum-zai-bot.message_reply_enabled', false)->andReturn($enabled);
        $settings->shouldReceive('get')->with('flarum-zai-bot.username', 'AIGirl')->andReturn('AIGirl');
        $settings->shouldReceive('get')->with('flarum-zai-bot.jina_optimization_mode', false)->andReturn(false);
        $settings->shouldReceive('get')->byDefault()->andReturnNull();

        return $settings;
    }

    /**
     * 将画像/记忆服务绑定进容器（任务内通过 resolve() 获取），并返回 mock 供断言。
     */
    protected function bindServices(): array
    {
        $portrait = Mockery::mock(PortraitService::class);
        $portrait->shouldReceive('getPortraitSummary')->with(self::HUMAN_ID)->andReturn('编程爱好者');

        $memory = Mockery::mock(MemoryService::class);
        $memory->shouldReceive('isAvailable')->andReturn(true);
        $memory->shouldReceive('generateEmbedding')->andReturn([0.1, 0.2]);
        $memory->shouldReceive('searchMemories')->andReturn([['created_at' => '2026-01-01 10:00', 'content' => '喜欢围棋']]);
        $memory->shouldReceive('storeMemory')->andReturn(true);

        $this->container->instance(PortraitService::class, $portrait);
        $this->container->instance(MemoryService::class, $memory);

        return [$portrait, $memory];
    }

    protected function aiMock(?string $reply = '你好，我在！'): AIService
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateReply')
            ->once()
            ->with(Mockery::type('string'), Mockery::type('array'), Mockery::type('array'))
            ->andReturn($reply);

        if ($reply !== null) {
            $ai->shouldReceive('parseSecretEval')->andReturnUsing(fn ($r) => $r);
        }

        return $ai;
    }

    /**
     * 创建一条真实的人类私信（dialog + dialog_user + dialog_messages）。
     * 注意：任务内部会自行 DialogMessage::find() 重新获取消息实例，作者、成员等
     * 都会走真实的关系查询，因此 users 行必须真实存在（Dialog::users() 通过
     * INNER JOIN users 取成员，posts/groups 表已由 bootstrap 提供）。
     */
    protected function createHumanMessage(bool $includeBotInDialog = true, ?string $priorContent = null): array
    {
        $human = new User();
        $human->id = self::HUMAN_ID;
        $human->username = 'human';
        $human->display_name = '人类用户';
        $human->email = 'human@example.com';
        $human->save();

        $botUser = new User();
        $botUser->id = self::BOT_ID;
        $botUser->username = 'AIGirl';
        $botUser->email = 'bot@bot.local';
        $botUser->save();

        $dialog = new Dialog();
        $dialog->type = 'direct';
        $dialog->save();

        $userIds = [self::HUMAN_ID];
        if ($includeBotInDialog) {
            $userIds[] = self::BOT_ID;
        }
        foreach ($userIds as $uid) {
            Capsule::table('dialog_user')->insert([
                'dialog_id' => $dialog->id,
                'user_id' => $uid,
                'joined_at' => '2026-01-01 00:00:00',
                'last_read_message_id' => 0,
                'last_read_at' => null,
            ]);
        }

        if ($priorContent !== null) {
            // 更早的消息（id 更小），用于验证 conversation_history 上下文
            $prior = new DialogMessage();
            $prior->dialog_id = $dialog->id;
            $prior->user_id = self::HUMAN_ID;
            $prior->content = $priorContent;
            $prior->save();
        }

        $message = new DialogMessage();
        $message->dialog_id = $dialog->id;
        $message->user_id = self::HUMAN_ID;
        $message->content = '你好';
        $message->save();

        return [$dialog, $message];
    }

    public function testRepliesToMessageAndDispatchesCreatedEvent(): void
    {
        [, $memory] = $this->bindServices();
        [$dialog, $message] = $this->createHumanMessage();

        $ai = $this->aiMock();
        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(fn ($e) => $e instanceof Created && $e->message->content === '你好，我在！'));

        $job = $this->job($message->id);
        $job->botUser = $this->botUser();

        $job->handle($ai, $this->settings(), $events);

        // 机器人的回复已入库
        $botMessage = DialogMessage::where('dialog_id', $dialog->id)->where('user_id', self::BOT_ID)->first();
        $this->assertNotNull($botMessage);
        $this->assertSame('你好，我在！', $botMessage->content);

        // 对话的最后一条消息已更新
        $dialog->refresh();
        $this->assertSame($botMessage->id, $dialog->last_message_id);

        // 好感度记录已建立
        $this->assertNotNull(BotAffinity::where('user_id', self::HUMAN_ID)->first());

        // 记忆存储路径确实被执行
        $memory->shouldHaveReceived('storeMemory')->once();
    }

    public function testPassesRichContextToAi(): void
    {
        $this->bindServices();
        [, $message] = $this->createHumanMessage(true, '之前的话题');

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateReply')
            ->once()
            ->with('你好', Mockery::on(function (array $context) {
                return $context['channel'] === 'message'
                    && $context['username'] === 'human'
                    && $context['display_name'] === '人类用户'
                    && $context['portrait_summary'] === '编程爱好者'
                    && $context['memories'][0]['content'] === '喜欢围棋'
                    && $context['conversation_history'][0]['content'] === '之前的话题';
            }), Mockery::type('array'))
            ->andReturn('回复');
        $ai->shouldReceive('parseSecretEval')->andReturnUsing(fn ($r) => $r);

        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->once();

        $job = $this->job($message->id);
        $job->botUser = $this->botUser();

        $job->handle($ai, $this->settings(), $events);
    }

    public function testSkipsWhenMessageReplyDisabled(): void
    {
        $this->bindServices();
        [, $message] = $this->createHumanMessage();

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateReply')->never();
        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->never();

        $job = $this->job($message->id);
        $job->botUser = $this->botUser();

        $job->handle($ai, $this->settings(false), $events);

        $this->assertNull(DialogMessage::where('user_id', self::BOT_ID)->first());
    }

    public function testSkipsWhenMessageNotFound(): void
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateReply')->never();
        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->never();

        $job = $this->job(999999);
        $job->botUser = $this->botUser();

        $job->handle($ai, $this->settings(), $events);
    }

    public function testSkipsWhenBotIsTheAuthor(): void
    {
        $this->bindServices();
        [, $message] = $this->createHumanMessage();

        // 把消息作者改成机器人自己
        $message->user_id = self::BOT_ID;
        $message->save();

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateReply')->never();
        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->never();

        $job = $this->job($message->id);
        $job->botUser = $this->botUser();

        $job->handle($ai, $this->settings(), $events);
    }

    public function testSkipsWhenBotNotInDialog(): void
    {
        $this->bindServices();
        [, $message] = $this->createHumanMessage(false);

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateReply')->never();
        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->never();

        $job = $this->job($message->id);
        $job->botUser = $this->botUser();

        $job->handle($ai, $this->settings(), $events);
    }

    public function testReturnsSilentlyWhenAiReturnsNull(): void
    {
        $this->bindServices();
        [, $message] = $this->createHumanMessage();

        $ai = $this->aiMock(null);
        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->never();

        $job = $this->job($message->id);
        $job->botUser = $this->botUser();

        $job->handle($ai, $this->settings(), $events);

        $this->assertNull(DialogMessage::where('user_id', self::BOT_ID)->first());
    }

    public function testKeepsMessageWhenCreatedDispatchThrows(): void
    {
        $this->bindServices();
        [$dialog, $message] = $this->createHumanMessage();

        $ai = $this->aiMock();
        $events = Mockery::mock(Dispatcher::class);
        // 同步监听器抛异常不应让任务失败重试（否则会重复生成回复）
        $events->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('realtime boom'));

        $job = $this->job($message->id);
        $job->botUser = $this->botUser();

        $job->handle($ai, $this->settings(), $events);

        $botMessage = DialogMessage::where('dialog_id', $dialog->id)->where('user_id', self::BOT_ID)->first();
        $this->assertNotNull($botMessage);
        $this->assertSame('你好，我在！', $botMessage->content);
    }
}
