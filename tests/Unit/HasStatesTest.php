<?php

namespace LynHuang\LaravelModelUtil\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LynHuang\LaravelModelUtil\Tests\TestCase;
use LynHuang\LaravelModelUtil\Traits\HasStates;

class StateOrder extends Model
{
    use HasStates;

    protected $guarded = [];
    public $timestamps = false;

    public static $hookLog = [];

    protected function stateTransitions()
    {
        return [
            'pending'   => ['to' => ['paid', 'canceled']],
            'paid'      => [
                'to'     => ['shipped', 'refunded'],
                // 进入 paid 前的守卫：金额为 0 时阻止
                'before' => function ($model, $from) {
                    return $model->amount > 0;
                },
                // 进入 paid 后的副作用
                'after'  => function ($model, $from) {
                    static::$hookLog[] = 'paid after from ' . $from;
                },
            ],
            'shipped'   => ['to' => ['completed']],
            'canceled'  => [
                'to'     => [],
                // 演示直接阻止的守卫
                'before' => function ($model, $from) {
                    return false;
                },
            ],
            'completed' => ['to' => []],
        ];
    }

    protected function stateLabels()
    {
        return ['pending' => '待支付', 'paid' => '已支付', 'shipped' => '已发货'];
    }
}

class HasStatesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        StateOrder::$hookLog = [];
    }

    private function makeOrder($status, $amount = 100)
    {
        $order = new StateOrder();
        $order->status = $status;
        $order->amount = $amount;

        return $order;
    }

    public function testCanTransitionTo()
    {
        $order = $this->makeOrder('pending');

        $this->assertTrue($order->canTransitionTo('status', 'paid'));
        $this->assertFalse($order->canTransitionTo('status', 'shipped'));
    }

    public function testTransitionToSetsAttribute()
    {
        $order = $this->makeOrder('pending');

        $order->transitionTo('status', 'paid');

        $this->assertEquals('paid', $order->status);
    }

    public function testInvalidTransitionThrowsAndKeepsState()
    {
        $order = $this->makeOrder('pending');

        $this->expectException(InvalidArgumentException::class);
        $order->transitionTo('status', 'shipped');
    }

    public function testBeforeHookBlocksTransition()
    {
        // 金额为 0，进入 paid 的 before 守卫返回 false
        $order = $this->makeOrder('pending', 0);

        try {
            $order->transitionTo('status', 'paid');
            $this->fail('守卫未生效');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('blocked by before hook', $e->getMessage());
        }

        $this->assertEquals('pending', $order->status);
        $this->assertSame([], StateOrder::$hookLog);
    }

    public function testAfterHookFiresOnEnter()
    {
        $order = $this->makeOrder('pending');

        $order->transitionTo('status', 'paid');

        $this->assertEquals('paid', $order->status);
        $this->assertSame(['paid after from pending'], StateOrder::$hookLog);
    }

    public function testStateLabel()
    {
        $order = $this->makeOrder('pending');

        $this->assertEquals('待支付', $order->stateLabel('status'));
        $this->assertEquals('已支付', $order->stateLabel('status', 'paid'));
        // 未配置映射的状态原样返回
        $this->assertEquals('completed', $order->stateLabel('status', 'completed'));
    }
}
