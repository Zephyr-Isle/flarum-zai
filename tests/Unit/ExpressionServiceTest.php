<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zephyrisle\FlarumZaiBot\Model\BotExpression;
use Zephyrisle\FlarumZaiBot\Service\ExpressionService;

class ExpressionServiceTest extends TestCase
{
    protected ExpressionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ExpressionService();
    }

    protected function tearDown(): void
    {
        BotExpression::query()->delete();
    }

    protected function storeRule(array $overrides = []): BotExpression
    {
        return $this->service->storePending(array_merge([
            'name' => '生气时哼开头',
            'source_type' => 'discussion',
            'situation' => '用户生气或不满时',
            'template' => '哼',
            'syntax' => "以'哼'开头表示不满",
            'recall_tags' => ['生气'],
            'evidence' => ['quote' => '哼，不想理你', 'source' => 'discussion'],
        ], $overrides));
    }

    public function testStorePendingDefaultsToPendingStatus(): void
    {
        $rule = $this->storeRule();

        $this->assertSame('pending', $rule->status);
        $this->assertSame('discussion', $rule->source_type);
        $this->assertSame('生气时哼开头', $rule->name);
        $this->assertSame(['quote' => '哼，不想理你', 'source' => 'discussion'], $rule->evidence);
        $this->assertSame(0, (int) $rule->use_count);
    }

    public function testStoreRejectsEmptyNameAndTemplate(): void
    {
        // storePending 不做长度校验（校验在 LearnExpressionTool），但空值应被清理
        $rule = $this->service->storePending(['name' => 'x', 'template' => ' y ', 'situation' => '  ']);

        $this->assertSame('y', $rule->template);
        $this->assertNull($rule->situation);
    }

    public function testApproveDisableAndListCounts(): void
    {
        $rule = $this->storeRule();
        $rule2 = $this->storeRule(['name' => '提问加嘛']);

        $result = $this->service->list('pending');
        $this->assertSame(2, $result['total']);
        $this->assertSame(2, $result['counts']['pending']);
        $this->assertSame(0, $result['counts']['active']);

        $this->service->approve($rule->id);
        $this->service->disable($rule2->id);

        $active = $this->service->list('active');
        $this->assertSame(1, $active['total']);

        $disabled = $this->service->list('disabled');
        $this->assertSame(1, $disabled['total']);

        $all = $this->service->list(null);
        $this->assertSame(2, $all['total']);
    }

    public function testSearchFiltersByNameAndTemplate(): void
    {
        $this->storeRule();
        $this->storeRule(['name' => '提问加嘛', 'template' => '嘛']);

        $result = $this->service->list(null, '哼');
        $this->assertSame(1, $result['total']);
        $this->assertSame('生气时哼开头', $result['items'][0]->name);
    }

    public function testUpdateFieldsEditsEditableFieldsOnly(): void
    {
        $rule = $this->storeRule();
        $this->service->approve($rule->id);

        $this->service->updateFields($rule->id, [
            'name' => '生气哼开头（改）',
            'situation' => '生气时',
            'template' => '哼哼',
            'syntax' => '更生气',
            'recall_tags' => ['生气', '不满'],
            'scope' => ['channels' => ['private_message']],
        ]);

        $fresh = BotExpression::find($rule->id);
        $this->assertSame('生气哼开头（改）', $fresh->name);
        $this->assertSame('生气时', $fresh->situation);
        $this->assertSame('哼哼', $fresh->template);
        $this->assertSame(['生气', '不满'], $fresh->recall_tags);
        $this->assertSame(['channels' => ['private_message']], $fresh->scope);
        // 状态与使用统计不受编辑影响
        $this->assertSame('active', $fresh->status);
        $this->assertSame(0, (int) $fresh->use_count);
    }

    public function testActiveRulesRespectScopeMatching(): void
    {
        $global = $this->storeRule(['name' => '全局规则']);
        $this->service->approve($global->id);

        $scoped = $this->storeRule([
            'name' => '仅私聊规则',
            'scope' => ['channels' => ['private_message']],
        ]);
        $this->service->approve($scoped->id);

        $userScoped = $this->storeRule([
            'name' => '仅用户1规则',
            'scope' => ['users' => ['1']],
        ]);
        $this->service->approve($userScoped->id);

        $pending = $this->storeRule(['name' => '待审核规则']);

        // 讨论频道 + 用户 2：全局规则命中，私聊/用户1 规则不命中，待审核不命中
        $rules = $this->service->activeRules('discussion', 2, 99);
        $names = array_map(fn (BotExpression $e) => $e->name, $rules);
        $this->assertSame(['全局规则'], $names);

        // 私聊频道 + 用户 1：全局 + 私聊 + 用户1 全部命中
        $rules = $this->service->activeRules('private_message', 1, null);
        $names = array_map(fn (BotExpression $e) => $e->name, $rules);
        sort($names);
        $this->assertSame(['仅用户1规则', '仅私聊规则', '全局规则'], $names);
    }

    public function testRecordUsageOnlyCountsActiveRules(): void
    {
        $active = $this->storeRule(['name' => '生效规则']);
        $this->service->approve($active->id);

        $pending = $this->storeRule(['name' => '待审核规则']);
        $disabled = $this->storeRule(['name' => '禁用规则']);
        $this->service->disable($disabled->id);

        $this->service->recordUsage(['生效规则', '待审核规则', '禁用规则', '不存在的规则']);

        $this->assertSame(1, (int) BotExpression::find($active->id)->use_count);
        $this->assertSame(0, (int) BotExpression::find($pending->id)->use_count);
        $this->assertSame(0, (int) BotExpression::find($disabled->id)->use_count);
    }

    public function testBuildInjectionTextMentionsExprUsedConvention(): void
    {
        $rule = $this->storeRule();
        $this->service->approve($rule->id);

        $text = $this->service->buildInjectionText([BotExpression::find($rule->id)]);

        $this->assertStringContainsString('生气时哼开头', $text);
        $this->assertStringContainsString('哼', $text);
        $this->assertStringContainsString('[ExprUsed', $text);

        $this->assertSame('', $this->service->buildInjectionText([]));
    }

    public function testDeleteRemovesRule(): void
    {
        $rule = $this->storeRule();

        $this->assertTrue($this->service->delete($rule->id));
        $this->assertNull(BotExpression::find($rule->id));
        $this->assertFalse($this->service->delete(999));
    }
}
