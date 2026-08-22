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
 *           'paid'      => ['to' => ['shipped', 'refunded']],
 *           'shipped'   => ['to' => ['completed']],
 *           'canceled'  => ['to' => []],
 *           'completed' => ['to' => []],
 *       ];
 *   }
 */
trait HasStates
{
    /**
     * 校验状态流转并切换
     *
     * @param string $field 状态字段
     * @param mixed $newState 目标状态
     * @return $this
     * @throws InvalidArgumentException 当流转不被允许时
     */
    public function transitionTo(string $field, $newState)
    {
        $current = $this->getAttribute($field);
        $allowed = $this->stateTransitions()[$current]['to'] ?? [];

        if (!in_array($newState, $allowed, true)) {
            throw new InvalidArgumentException(
                "Cannot transition '{$field}' from '{$current}' to '{$newState}'"
            );
        }

        $this->setAttribute($field, $newState);

        return $this;
    }

    /**
     * 是否允许流转到目标状态（不修改数据）
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
     * 状态流转规则定义，子类必须实现
     *
     * @return array
     */
    protected function stateTransitions(): array
    {
        return [];
    }
}
