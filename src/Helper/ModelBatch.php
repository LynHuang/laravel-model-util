<?php

namespace LynHuang\LaravelModelUtil\Helper;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * 绑定模型的批量操作代理（BatchHelper::for() 的返回值）
 *
 * 自动解析模型的表名、主键、时间戳字段、软删除字段与数据库连接，
 * 底层委托给 BatchHelper 的原生方法。
 *
 * 用法：
 *   BatchHelper::for(User::class)->update($rows);
 *   BatchHelper::for(User::class)->insert($rows);
 *   BatchHelper::for(User::class)->upsert($rows, ['email']);
 *   BatchHelper::for(User::class)->delete($ids);
 *   BatchHelper::for(User::class)->softDelete($ids);
 */
class ModelBatch
{
    /**
     * 模型类名
     *
     * @var string
     */
    private $modelClass;

    /**
     * 用于解析模型配置的空实例
     *
     * @var Model
     */
    private $model;

    /**
     * 原生批量操作辅助
     *
     * @var BatchHelper
     */
    private $helper;

    /**
     * @param string $modelClass Eloquent 模型类名
     * @throws LogicException 非 Eloquent 模型类时
     */
    public function __construct(string $modelClass)
    {
        if (!is_subclass_of($modelClass, Model::class)) {
            throw new LogicException("{$modelClass} 不是 Eloquent 模型类，无法使用 BatchHelper::for()");
        }

        $this->modelClass = $modelClass;
        $this->model      = new $modelClass;
        $this->helper     = new BatchHelper;
    }

    /**
     * 批量更新（CASE WHEN），主键与 updated_at 字段自动按模型解析
     *
     * @param array $rows 每行必须包含主键
     * @param int $chunkSize 分块大小
     * @param bool|null $timestamps 是否自动维护更新时间，默认跟随模型的 timestamps 配置
     * @return int 受影响的行数
     */
    public function update(array $rows, int $chunkSize = 100, ?bool $timestamps = null)
    {
        return $this->helper->batchUpdate(
            $this->model->getTable(),
            $rows,
            $chunkSize,
            $this->model->getKeyName(),
            $timestamps ?? $this->usesTimestamps(),
            $this->model->getUpdatedAtColumn(),
            $this->model->getConnectionName()
        );
    }

    /**
     * 批量插入，自动补充 created_at / updated_at（模型开启时间戳时）
     *
     * @param array $rows
     * @param int $chunkSize 分块大小
     * @return int 插入的行数
     */
    public function insert(array $rows, int $chunkSize = 1000)
    {
        if ($this->usesTimestamps()) {
            $rows = $this->withTimestamps($rows);
        }

        return $this->helper->batchInsert(
            $this->model->getTable(),
            $rows,
            $chunkSize,
            $this->model->getConnectionName()
        );
    }

    /**
     * 批量插入或更新（upsert），自动补充时间戳（模型开启时间戳时）
     *
     * @param array $rows
     * @param array $uniqueBy 唯一键字段
     * @param array $updateColumns 冲突时更新的字段，为空时更新全部非唯一键字段
     * @param int $chunkSize 分块大小
     * @return int 受影响的行数
     */
    public function upsert(array $rows, array $uniqueBy, array $updateColumns = [], int $chunkSize = 1000)
    {
        if ($this->usesTimestamps()) {
            $rows = $this->withTimestamps($rows);
        }

        return $this->helper->batchUpsert(
            $this->model->getTable(),
            $rows,
            $uniqueBy,
            $updateColumns,
            $chunkSize,
            $this->model->getConnectionName()
        );
    }

    /**
     * 按主键批量删除
     *
     * @param array $ids 主键值数组
     * @param int $chunkSize 分块大小
     * @return int 删除的行数
     */
    public function delete(array $ids, int $chunkSize = 1000)
    {
        return $this->helper->batchDelete(
            $this->model->getTable(),
            $ids,
            $chunkSize,
            $this->model->getKeyName(),
            $this->model->getConnectionName()
        );
    }

    /**
     * 批量软删除（模型需使用 SoftDeletes）
     *
     * @param array $ids 主键值数组
     * @param int $chunkSize 分块大小
     * @return int 受影响的行数
     * @throws LogicException 模型未使用 SoftDeletes 时
     */
    public function softDelete(array $ids, int $chunkSize = 1000)
    {
        return $this->helper->batchSoftDelete(
            $this->model->getTable(),
            $ids,
            $chunkSize,
            $this->model->getKeyName(),
            $this->deletedAtColumn(),
            $this->model->getConnectionName()
        );
    }

    /**
     * 批量恢复软删除（模型需使用 SoftDeletes）
     *
     * @param array $ids 主键值数组
     * @param int $chunkSize 分块大小
     * @return int 受影响的行数
     * @throws LogicException 模型未使用 SoftDeletes 时
     */
    public function restore(array $ids, int $chunkSize = 1000)
    {
        return $this->helper->batchRestore(
            $this->model->getTable(),
            $ids,
            $chunkSize,
            $this->model->getKeyName(),
            $this->deletedAtColumn(),
            $this->model->getConnectionName()
        );
    }

    /**
     * 为每行补充 created_at / updated_at（已有值不覆盖）
     */
    private function withTimestamps(array $rows)
    {
        $now         = now()->format('Y-m-d H:i:s');
        $createdAt   = $this->model->getCreatedAtColumn();
        $updatedAt   = $this->model->getUpdatedAtColumn();

        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }
            $row[$createdAt] = $row[$createdAt] ?? $now;
            $row[$updatedAt] = $row[$updatedAt] ?? $now;
        }
        unset($row);

        return $rows;
    }

    /**
     * 模型是否开启时间戳
     */
    private function usesTimestamps()
    {
        return $this->model->usesTimestamps();
    }

    /**
     * 软删除字段名（模型需使用 SoftDeletes）
     *
     * @return string
     * @throws LogicException
     */
    private function deletedAtColumn()
    {
        if (!in_array(SoftDeletes::class, class_uses_recursive($this->modelClass))) {
            throw new LogicException($this->modelClass . ' 未使用 SoftDeletes，无法批量软删除 / 恢复');
        }

        return $this->model->getDeletedAtColumn();
    }
}
