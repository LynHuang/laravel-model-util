<?php

namespace LynHuang\LaravelModelUtil\Helper;

/**
 * 树形结构辅助
 *
 * 适用于栏目、菜单、分类等有父子关系的扁平数据。
 */
class TreeHelper
{
    /**
     * 将扁平数组构建为树形结构（O(n)）
     *
     * @param array $items 扁平数组
     * @param string $idKey 主键字段
     * @param string $parentKey 父级字段
     * @param string $childrenKey 子节点字段
     * @param mixed $rootId 根节点父级值，默认 null
     * @return array
     */
    public static function toTree(array $items, string $idKey = 'id', string $parentKey = 'parent_id', string $childrenKey = 'children', $rootId = null)
    {
        $children = [];
        foreach ($items as $item) {
            $parent = $item[$parentKey] ?? null;
            $children[$parent === null ? '' : $parent][] = $item;
        }

        return self::buildBranch($children, $rootId, $idKey, $childrenKey);
    }

    /**
     * 递归组装某一分支
     */
    private static function buildBranch(array &$children, $parentId, string $idKey, string $childrenKey)
    {
        $key = $parentId === null ? '' : $parentId;
        $branch = [];

        foreach ($children[$key] ?? [] as $item) {
            $item[$childrenKey] = self::buildBranch($children, $item[$idKey], $idKey, $childrenKey);
            $branch[] = $item;
        }

        return $branch;
    }

    /**
     * 将树形结构展开为扁平数组（保留 children 时可用 toTree 还原）
     *
     * @param array $tree
     * @param string $childrenKey
     * @return array
     */
    public static function flatten(array $tree, string $childrenKey = 'children')
    {
        $result = [];

        foreach ($tree as $node) {
            $children = $node[$childrenKey] ?? [];
            unset($node[$childrenKey]);
            $result[] = $node;
            if ($children) {
                $result = array_merge($result, self::flatten($children, $childrenKey));
            }
        }

        return $result;
    }

    /**
     * 获取指定节点的所有后代 id（含直接与间接子级）
     *
     * @param array $items 扁平数组
     * @param mixed $id 节点 id
     * @param bool $includeSelf 是否包含自身
     * @param string $idKey
     * @param string $parentKey
     * @return array
     */
    public static function descendants(array $items, $id, bool $includeSelf = false, string $idKey = 'id', string $parentKey = 'parent_id')
    {
        $result = [];

        foreach ($items as $item) {
            if (($item[$parentKey] ?? null) == $id) {
                $result[] = $item[$idKey];
                $result = array_merge($result, self::descendants($items, $item[$idKey], false, $idKey, $parentKey));
            }
        }

        if ($includeSelf) {
            array_unshift($result, $id);
        }

        return $result;
    }

    /**
     * 获取指定节点的所有祖先 id（含父级、祖父级，按从近到远排序）
     *
     * @param array $items 扁平数组
     * @param mixed $id 节点 id
     * @param string $idKey
     * @param string $parentKey
     * @return array
     */
    public static function ancestors(array $items, $id, string $idKey = 'id', string $parentKey = 'parent_id')
    {
        $map = [];
        foreach ($items as $item) {
            $map[$item[$idKey]] = $item;
        }

        $result = [];
        $current = $map[$id] ?? null;

        while ($current && isset($map[$current[$parentKey] ?? null])) {
            $parent = $map[$current[$parentKey]];
            $result[] = $parent[$idKey];
            $current = $parent;
        }

        return $result;
    }
}
