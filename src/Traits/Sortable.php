<?php

namespace LynHuang\LaravelModelUtil\Traits;

/**
 * 排序辅助 Trait
 *
 * 基于 weight 字段实现上移 / 下移 / 置顶 / 置底 / 批量重排。
 * 使用前需确保表中有排序字段（默认 weight）。
 */
trait Sortable
{
    /**
     * 排序字段名，子类可按需覆盖
     *
     * @var string
     */
    protected $sortColumn = 'weight';

    /**
     * 按排序字段升序查询
     *
     * @param $query
     * @return mixed
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy($this->sortColumn);
    }

    /**
     * 上移一位
     */
    public function moveUp()
    {
        $prev = static::query()
            ->where($this->sortColumn, '<', $this->{$this->sortColumn})
            ->orderByDesc($this->sortColumn)
            ->first();

        if ($prev) {
            $this->swapOrder($prev);
        }

        return $this;
    }

    /**
     * 下移一位
     */
    public function moveDown()
    {
        $next = static::query()
            ->where($this->sortColumn, '>', $this->{$this->sortColumn})
            ->orderBy($this->sortColumn)
            ->first();

        if ($next) {
            $this->swapOrder($next);
        }

        return $this;
    }

    /**
     * 置顶
     */
    public function moveToTop()
    {
        $min = (int)static::query()->min($this->sortColumn);
        $this->{$this->sortColumn} = $min - 1;
        $this->save();

        return $this;
    }

    /**
     * 置底
     */
    public function moveToBottom()
    {
        $max = (int)static::query()->max($this->sortColumn);
        $this->{$this->sortColumn} = $max + 1;
        $this->save();

        return $this;
    }

    /**
     * 按传入的主键顺序整体重排
     *
     * @param array $orderedIds 排好序的主键数组
     */
    public function reorder(array $orderedIds)
    {
        $start = (int)static::query()->min($this->sortColumn);

        foreach (array_values($orderedIds) as $index => $id) {
            static::query()->whereKey($id)->update([
                $this->sortColumn => $start + $index + 1,
            ]);
        }
    }

    /**
     * 交换当前模型与目标模型的排序值
     *
     * @param $other
     */
    protected function swapOrder($other)
    {
        $tmp = $this->{$this->sortColumn};
        $this->{$this->sortColumn} = $other->{$this->sortColumn};
        $other->{$this->sortColumn} = $tmp;

        $this->save();
        $other->save();
    }
}
