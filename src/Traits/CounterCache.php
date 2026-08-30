<?php

namespace LynHuang\LaravelModelUtil\Traits;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * 计数缓存 Trait
 *
 * 关联模型新增 / 删除 / 软删恢复时，自动维护父模型上的计数字段，
 * 省掉列表页的 withCount 查询。
 *
 * 使用方式（在父模型中声明）：
 *
 *   class User extends Model
 *   {
 *       use CounterCache;
 *
 *       protected $countCaches = [
 *           'comments_count' => Comment::class,               // 默认外键 user_id
 *           'articles_count' => [Article::class, 'author_id'], // 自定义外键
 *       ];
 *   }
 *
 * 计数通过 increment / decrement 原地增减（不做全表统计），
 * 子模型的软删（deleted）-1、恢复（restored）+1 自动保持计数正确。
 *
 * 注意：
 * - 子模型通过 Query Builder 批量增删（Model::query()->delete() / BatchHelper）不触发事件，计数不会更新；
 * - 计数可能因历史数据漂移，可调用 $user->syncCountCache() 全量校准。
 */
trait CounterCache
{
    /**
     * 注册子模型事件，维护父模型计数字段
     */
    public static function bootCounterCache()
    {
        foreach (static::counterCacheMap() as $column => $config) {
            [$childClass, $foreignKey] = $config;

            $apply = function ($child, $delta) use ($column, $foreignKey) {
                $parentKey = $child->{$foreignKey} ?? null;
                if ($parentKey === null) {
                    return;
                }
                $query = static::query()->whereKey($parentKey);
                $delta > 0 ? $query->increment($column) : $query->decrement($column);
            };

            $childClass::created(function ($child) use ($apply) {
                $apply($child, 1);
            });
            $childClass::deleted(function ($child) use ($apply) {
                $apply($child, -1);
            });

            // static::restored() 注册方法由 SoftDeletes 提供，仅在软删子模型上注册
            if (in_array(SoftDeletes::class, class_uses_recursive($childClass))) {
                $childClass::restored(function ($child) use ($apply) {
                    $apply($child, 1);
                });
            }
        }
    }

    /**
     * 解析计数缓存配置
     *
     * @return array 计数字段 => [子模型类名, 外键字段]
     */
    protected static function counterCacheMap()
    {
        $instance = new static;
        $declared = property_exists($instance, 'countCaches') ? $instance->countCaches : [];
        if (!is_array($declared)) {
            return [];
        }

        $defaultForeignKey = Str::snake(class_basename($instance)) . '_id';
        $map = [];

        foreach ($declared as $column => $config) {
            if (is_string($config)) {
                $map[$column] = [$config, $defaultForeignKey];
            } elseif (is_array($config) && count($config) >= 2 && is_string($config[0])) {
                $map[$column] = [$config[0], $config[1]];
            }
        }

        return $map;
    }

    /**
     * 重新统计并修正计数字段（数据漂移时手动校准）
     *
     * @param string|null $column 计数字段，null 校准全部
     * @return $this
     */
    public function syncCountCache(?string $column = null)
    {
        $columns = $column !== null ? [$column] : array_keys(static::counterCacheMap());

        $updates = [];
        foreach ($columns as $target) {
            $config = static::counterCacheMap()[$target] ?? null;
            if ($config === null) {
                continue;
            }
            [$childClass, $foreignKey] = $config;

            // 子模型使用 SoftDeletes 时全局作用域会自动排除软删数据
            $updates[$target] = $childClass::query()->where($foreignKey, $this->getKey())->count();
        }

        if ($updates) {
            $this->forceFill($updates)->saveQuietly();
        }

        return $this;
    }
}
