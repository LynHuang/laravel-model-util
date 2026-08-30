<?php

namespace LynHuang\LaravelModelUtil\Traits;

use Illuminate\Database\Eloquent\Builder;
use LynHuang\LaravelModelUtil\Exceptions\OptimisticLockException;

/**
 * 乐观锁 Trait
 *
 * 基于版本号字段（默认 version）检测并发修改：
 * 每次保存自动递增版本号，UPDATE 条件带上旧版本号，
 * 版本不一致（数据已被其他请求修改）时抛出 OptimisticLockException。
 *
 * 使用方式：
 *   use OptimisticLocking;
 *   // 表中需有整型版本号字段（默认 version，建议 NOT NULL DEFAULT 0），
 *   // 可声明 protected $optimisticLockColumn = 'version'; 覆盖字段名
 *
 * 典型用法：
 *   try {
 *       $order->fill([...])->save();
 *   } catch (OptimisticLockException $e) {
 *       // 数据已被其他请求修改，可选择提示用户刷新，
 *       // 或使用 saveWithRetry() 以"最后写入胜出"方式自动重试
 *   }
 *
 * 注意：批量更新（Model::query()->update() / BatchHelper）不经过模型保存流程，
 * 不会校验或递增版本号。
 */
trait OptimisticLocking
{
    /**
     * 注册模型事件
     */
    public static function bootOptimisticLocking()
    {
        // 新增时初始化版本号
        static::creating(function ($model) {
            $column = $model->optimisticLockColumn();
            if ($model->getAttribute($column) === null) {
                $model->setAttribute($column, 0);
            }
        });
    }

    /**
     * 乐观锁字段名，模型可声明 protected $optimisticLockColumn = 'version'; 覆盖
     *
     * @return string
     */
    protected function optimisticLockColumn()
    {
        return property_exists($this, 'optimisticLockColumn') ? $this->optimisticLockColumn : 'version';
    }

    /**
     * 保存并在乐观锁冲突时自动重试
     *
     * 冲突时会重新从数据库读取最新数据，再在其基础上重新应用本次修改的字段，
     * 即修改的字段以"最后写入胜出"方式覆盖，未修改字段保留数据库最新值。
     * 超过重试次数仍冲突时抛出 OptimisticLockException。
     *
     * @param int $maxAttempts 最大尝试次数（含首次）
     * @return bool
     * @throws OptimisticLockException 重试次数用尽仍冲突，或记录已被删除时
     */
    public function saveWithRetry(int $maxAttempts = 2)
    {
        $attempts = 0;

        while (true) {
            $attempts++;

            try {
                return $this->save();
            } catch (OptimisticLockException $e) {
                if ($attempts >= max(1, $maxAttempts)) {
                    throw $e;
                }

                // 记录本次要应用的修改，取数据库最新数据后在其基础上重放
                $dirty = $this->getDirty();

                $fresh = static::query()->whereKey($this->getKey())->first();
                if ($fresh === null) {
                    // 记录已被删除，无法重试
                    throw $e;
                }

                $this->setRawAttributes($fresh->getAttributes(), true);
                foreach ($dirty as $key => $value) {
                    $this->setAttribute($key, $value);
                }
            }
        }
    }

    /**
     * 执行更新：UPDATE 条件追加旧版本号，0 行受影响说明版本已被并发修改
     *
     * @param Builder $query
     * @return bool
     * @throws OptimisticLockException
     */
    protected function performUpdate(Builder $query)
    {
        // 与 Eloquent 默认行为一致：updating 事件返回 false 时取消更新
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        $column = $this->optimisticLockColumn();

        // 未手动设置版本号时自动递增
        if (!$this->isDirty($column)) {
            $this->setAttribute($column, (int)$this->getRawOriginal($column) + 1);
        }

        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $dirty = $this->getDirty();

        if (count($dirty) > 0) {
            $originalVersion = $this->getRawOriginal($column);
            $lockQuery       = $this->setKeysForSaveQuery($query);

            // 兼容历史数据：版本号为 NULL 的行按 NULL 匹配，成功保存后即写入初始版本
            if ($originalVersion === null) {
                $lockQuery->whereNull($column);
            } else {
                $lockQuery->where($column, $originalVersion);
            }

            $affected = $lockQuery->update($dirty);

            if ($affected === 0) {
                throw new OptimisticLockException(
                    class_basename(static::class) . '#' . $this->getKey()
                    . " 已被其他请求修改（{$column} 期望 {$originalVersion}），请刷新后重试"
                );
            }

            $this->syncChanges();
            $this->fireModelEvent('updated', false);
        }

        return true;
    }
}
