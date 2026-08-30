<?php

namespace LynHuang\LaravelModelUtil\Traits;

use InvalidArgumentException;

/**
 * 状态机切换 Trait
 *
 * 子类通过 stateTransitions() 声明状态流转规则，如：
 *
 *   protected function stateTransitions()
 *   {
 *       return [
 *           'pending'   => ['to' => ['paid', 'canceled']],
 *           'paid'      => [
 *               'to'     => ['shipped', 'refunded'],
 *               // 进入 paid 前的守卫：返回 false 时流转被阻止并抛出异常
 *               'before' => function ($model, $from) {
 *                   return $model->amount > 0;
 *               },
 *               // 进入 paid 后的副作用
 *               'after'  => function ($model, $from) {
 *                   Notice::ship($model);
 *               },
 *           ],
 *           'shipped'   => ['to' => ['completed']],
 *           'canceled'  => ['to' => []],
 *           'completed' => ['to' => []],
 *       ];
 *   }
 *
 * 可选通过 stateLabels() 声明状态的展示名：
 *
 *   protected function stateLabels()
 *   {
 *       return ['pending' => '待支付', 'paid' => '已支付', ...];
 *   }
 *
 * 注意：钩子在流转时（内存中）触发，与保存时机无关；
 * before / after 回调签名统一为 function ($model, $fromState)。
 */
trait HasStates
{
    /**
     * 校验状态流转并切换
     *
     * @param string $field 状态字段
     * @param mixed $newState 目标状态
     * @return $this
     * @throws InvalidArgumentException 当流转不被允许，或目标状态 before 守卫返回 false 时
     */
    public function transitionTo(string $field, $newState)
    {
        $current = $this->getAttribute($field);
        $rules   = $this->stateTransitions();
        $allowed = $rules[$current]['to'] ?? [];

        if (!in_array($newState, $allowed, true)) {
            throw new InvalidArgumentException(
                "Cannot transition '{$field}' from '{$current}' to '{$newState}'"
            );
        }

        $hooks = $rules[$newState] ?? [];

        // 目标状态的 before 守卫：返回 false 阻止流转
        if (isset($hooks['before']) && is_callable($hooks['before'])) {
            if (call_user_func($hooks['before'], $this, $current) === false) {
                throw new InvalidArgumentException(
                    "Transition '{$field}' from '{$current}' to '{$newState}' was blocked by before hook"
                );
            }
        }

        $this->setAttribute($field, $newState);

        // 目标状态的 after 副作用
        if (isset($hooks['after']) && is_callable($hooks['after'])) {
            call_user_func($hooks['after'], $this, $current);
        }

        return $this;
    }

    /**
     * 是否允许流转到目标状态（不修改数据、不触发钩子）
     *
     * @param string $field
     * @param mixed $newState
     * @return bool
     */
    public function canTransitionTo(string $field, $newState): bool
    {
        $current = $this->getAttribute($field);

        return in_array($newState, $this->stateTransitions()[$current]['to'] ?? [], true);
    }

    /**
     * 获取状态展示名（未声明映射时原样返回状态值）
     *
     * @param string $field 状态字段
     * @param mixed $state 状态值，null 取字段当前值
     * @return mixed
     */
    public function stateLabel(string $field, $state = null)
    {
        $state  = $state ?? $this->getAttribute($field);
        $labels = $this->stateLabels();

        return $labels[$state] ?? $state;
    }

    /**
     * 状态流转规则定义，子类必须实现
     *
     * 每个状态可含 'to'（允许流转到的目标状态列表）、
     * 'before'（作为目标状态流入前的守卫，返回 false 阻止流转）、
     * 'after'（作为目标状态流入后的副作用）。
     *
     * @return array
     */
    protected function stateTransitions(): array
    {
        return [];
    }

    /**
     * 状态展示名映射，子类按需实现
     *
     * @return array 状态值 => 展示名
     */
    protected function stateLabels(): array
    {
        return [];
    }
}
