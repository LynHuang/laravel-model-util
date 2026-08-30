<?php

namespace LynHuang\LaravelModelUtil\Traits;

use Illuminate\Support\Collection;
use LynHuang\LaravelModelUtil\Helper\TreeHelper;

/**
 * 模型树 Trait（parent_id 邻接表模式）
 *
 * 把 TreeHelper 的数组能力接到 Eloquent 上，提供子孙 / 祖先查询。
 * 使用前需表中有父级字段（默认 parent_id）。
 *
 * 模型中可声明：protected $treeParentColumn = 'parent_id';
 *
 * 注意：descendant / ancestor 计算先取全部 [主键, 父级字段] 在内存中组装，
 * 适合栏目、菜单、分类等中小规模树；超大规模（百万级）建议改用闭包表 / 路径枚举。
 */
trait HasTree
{
    /**
     * 父级字段名，模型可声明 protected $treeParentColumn = 'parent_id'; 覆盖
     *
     * @return string
     */
    protected function treeParentColumn()
    {
        return property_exists($this, 'treeParentColumn') ? $this->treeParentColumn : 'parent_id';
    }

    /**
     * 顶级节点查询（父级字段为空）
     *
     * @param $query
     * @return mixed
     */
    public function scopeRoots($query)
    {
        return $query->whereNull($this->treeParentColumn());
    }

    /**
     * 指定父级的直接子节点查询
     *
     * @param $query
     * @param mixed $parentId 父级 id，null 表示父级为空的节点
     * @return mixed
     */
    public function scopeChildrenOf($query, $parentId)
    {
        $column = $this->treeParentColumn();

        return $parentId === null
            ? $query->whereNull($column)
            : $query->where($column, $parentId);
    }

    /**
     * 获取指定节点的所有后代 id（含直接与间接子级）
     *
     * @param mixed $id 节点 id，默认当前节点
     * @param bool $includeSelf 是否包含自身
     * @return array
     */
    public function descendantIds($id = null, $includeSelf = false)
    {
        $id = $id ?? $this->getKey();

        return TreeHelper::descendants($this->treeItems(), $id, $includeSelf, $this->getKeyName(), $this->treeParentColumn());
    }

    /**
     * 后代节点查询：按后代 id 集合过滤（一次性取 [主键, 父级字段] 在内存组装）
     *
     * @param $query
     * @param mixed $id 节点 id
     * @param bool $includeSelf 是否包含自身
     * @return mixed
     */
    public function scopeDescendantsOf($query, $id, $includeSelf = false)
    {
        return $query->whereKey($this->descendantIds($id, $includeSelf));
    }

    /**
     * 当前节点的直接子节点
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getChildren()
    {
        return static::query()->childrenOf($this->getKey())->get();
    }

    /**
     * 当前节点的所有后代（深度优先，顺序与数据出现顺序一致）
     *
     * @param bool $includeSelf 是否包含自身
     * @return Collection
     */
    public function getDescendants($includeSelf = false)
    {
        return $this->fetchOrdered($this->descendantIds($this->getKey(), $includeSelf));
    }

    /**
     * 当前节点的所有祖先（父级、祖父级...，由近到远）
     *
     * @return Collection
     */
    public function getAncestors()
    {
        $ids = TreeHelper::ancestors($this->treeItems(), $this->getKey(), $this->getKeyName(), $this->treeParentColumn());

        return $this->fetchOrdered($ids);
    }

    /**
     * 取全表的 [主键 => 父级] 轻量映射（不做全字段查询）
     *
     * @return array
     */
    private function treeItems()
    {
        $idKey      = $this->getKeyName();
        $parentKey  = $this->treeParentColumn();
        $items      = [];

        foreach (static::query()->select([$idKey, $parentKey])->get() as $row) {
            $items[] = [$idKey => $row->{$idKey}, $parentKey => $row->{$parentKey}];
        }

        return $items;
    }

    /**
     * 按 id 顺序取回完整模型（保持传入顺序：TreeHelper 的遍历顺序）
     *
     * @param array $ids
     * @return Collection
     */
    private function fetchOrdered(array $ids)
    {
        if (empty($ids)) {
            return static::query()->whereRaw('1 = 0')->get();
        }

        $keyName = $this->getKeyName();
        $models  = static::query()->whereKey($ids)->get()->keyBy($keyName);

        return collect($ids)
            ->map(function ($id) use ($models, $keyName) {
                return $models[$id] ?? null;
            })
            ->filter()
            ->values();
    }
}
