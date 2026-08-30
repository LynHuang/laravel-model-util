<?php

namespace LynHuang\LaravelModelUtil\Traits;

use LynHuang\LaravelModelUtil\Helper\BatchHelper;

/**
 * 排序辅助 Trait
 *
 * 基于 weight 字段实现上移 / 下移 / 置顶 / 置底 / 批量重排。
 * 使用前需确保表中有排序字段（默认 weight）。
 *
 * 模型中可声明：
 *   protected $sortColumn = 'weight';               // 排序字段，默认 weight
 *   protected $sortGroupColumns = ['category_id'];  // 分组字段，声明后移动/置顶/置底限定在同组内
 *
 * 注意：排序字段与分组字段由模型声明，Trait 内通过方法读取
 * （Trait 与模型重复定义同名属性且默认值不同会触发 PHP fatal）。
 */
trait Sortable
{
    /**
     * 排序字段名，模型中声明：protected $sortColumn = 'weight';
     *
     * @return string
     */
    protected function sortColumn()
    {
        return property_exists($this, 'sortColumn') ? $this->sortColumn : 'weight';
    }

    /**
     * 分组排序字段，模型中声明：protected $sortGroupColumns = ['category_id'];
     *
     * @return array
     */
    protected function sortGroupColumns()
    {
        return property_exists($this, 'sortGroupColumns') ? $this->sortGroupColumns : [];
    }

    /**
     * 按排序字段升序查询
     *
     * @param $query
     * @return mixed
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy($this->sortColumn());
    }

    /**
     * 上移一位（有分组字段时在同一分组内移动）
     */
    public function moveUp()
    {
        $column = $this->sortColumn();
        $prev   = $this->sortGroupQuery(static::query())
            ->where($column, '<', $this->getAttribute($column))
            ->orderByDesc($column)
            ->first();

        if ($prev) {
            $this->swapOrder($prev);
        }

        return $this;
    }

    /**
     * 下移一位（有分组字段时在同一分组内移动）
     */
    public function moveDown()
    {
        $column = $this->sortColumn();
        $next   = $this->sortGroupQuery(static::query())
            ->where($column, '>', $this->getAttribute($column))
            ->orderBy($column)
            ->first();

        if ($next) {
            $this->swapOrder($next);
        }

        return $this;
    }

    /**
     * 置顶（有分组字段时为组内置顶）
     */
    public function moveToTop()
    {
        $column = $this->sortColumn();
        $min    = (int)$this->sortGroupQuery(static::query())->min($column);
        $this->setAttribute($column, $min - 1);
        $this->save();

        return $this;
    }

    /**
     * 置底（有分组字段时为组内置底）
     */
    public function moveToBottom()
    {
        $column = $this->sortColumn();
        $max    = (int)$this->sortGroupQuery(static::query())->max($column);
        $this->setAttribute($column, $max + 1);
        $this->save();

        return $this;
    }

    /**
     * 按传入的主键顺序整体重排（单条 CASE WHEN 批量更新）
     *
     * 使用分组字段时，$orderedIds 应为同一分组内的排序结果。
     *
     * @param array $orderedIds 排好序的主键数组
     */
    public function reorder(array $orderedIds)
    {
        if (empty($orderedIds)) {
            return;
        }

        $column  = $this->sortColumn();
        $keyName = $this->getKeyName();
        $start   = (int)static::query()->min($column);

        $rows = [];
        foreach (array_values($orderedIds) as $index => $id) {
            $rows[] = [$keyName => $id, $column => $start + $index + 1];
        }

        // 逐条 update 在长列表下开销大，改用单条 CASE WHEN 批量更新
        (new BatchHelper)->batchUpdate($this->getTable(), $rows, 100, $keyName, false);
    }

    /**
     * 交换当前模型与目标模型的排序值
     *
     * @param $other
     */
    protected function swapOrder($other)
    {
        $column = $this->sortColumn();

        $tmp = $this->getAttribute($column);
        $this->setAttribute($column, $other->getAttribute($column));
        $other->setAttribute($column, $tmp);

        $this->save();
        $other->save();
    }

    /**
     * 为查询追加分组条件（分组字段值与本模型一致的记录）
     *
     * @param $query
     * @return mixed
     */
    protected function sortGroupQuery($query)
    {
        foreach ($this->sortGroupColumns() as $groupColumn) {
            $value = $this->getAttribute($groupColumn);
            if (is_null($value)) {
                $query->whereNull($groupColumn);
            } else {
                $query->where($groupColumn, $value);
            }
        }

        return $query;
    }
}
