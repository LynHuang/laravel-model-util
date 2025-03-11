<?php
/**
 * Created by PhpStorm.
 * User: Lyn
 * Date: 2025/3/8
 * Time: 15:28
 */

namespace LynHuang\LaravelModelUtil\Helper;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BatchHelper
{
    /**
     * 批量更新数据
     * @param $table
     * @param array $updateArray 必须有ID
     * @param int $chunkSize
     * @param string $primaryKey
     * @return void
     */
     /**
     * 批量更新数据（使用CASE WHEN语句实现高效批量更新）
     *
     * @param string $table 需要更新的表名
     * @param array $updateArray 需要更新的数据数组，每个元素必须包含主键字段
     * @param int $chunkSize 分块处理的大小，默认100条/块
     * @param string $primaryKey 主键字段名称，默认'id'
     * @return void
     * @throws \Exception 当数据格式错误或SQL执行失败时抛出异常
     *
     * 实现原理：
     * 1. 将数据分块处理，避免单条SQL过大
     * 2. 使用CASE WHEN条件语句构建批量更新
     * 3. 参数绑定方式防止SQL注入
     * 4. 在事务中执行保证原子性
     */
    public function batchUpdate($table, array $updateArray, int $chunkSize = 100, string $primaryKey = 'id')
    {
        $data = collect($updateArray);

        // 分块处理数据
        $data->chunk($chunkSize)->each(function ($chunk) use ($table, $primaryKey) {
            // 初始化SQL组件
            $cases = [];    // 存储各个字段的CASE语句
            $bindings = []; // 参数绑定值
            $ids = [];      // 当前块涉及的主键集合

            // 提取所有需要更新的字段（排除主键）
            $fields = array_keys($chunk->first());
            $fields = array_diff($fields, [$primaryKey]);

            // 初始化每个字段的CASE语句开头
            foreach ($fields as $field) {
                $cases[$field] = "`$field` = CASE ";
            }

            // 构建CASE WHEN语句和绑定参数
            foreach ($chunk as $datum) {
                // 校验主键是否存在
                if (!isset($datum[$primaryKey])) {
                    throw new \InvalidArgumentException("Missing primary key '$primaryKey' in update data");
                }

                foreach ($fields as $field) {
                    $value = $datum[$field] ?? null;
                    // 拼接WHEN条件语句
                    $cases[$field] .= "WHEN `$primaryKey` = ? THEN ? ";
                    // 添加主键值和字段值到绑定参数
                    $bindings[] = $datum[$primaryKey];
                    $bindings[] = $this->format2SqlValue($value);
                }
                $ids[] = $datum[$primaryKey];
            }

            // 组合完整SQL语句
            $updateSql = "UPDATE `$table` SET ";
            foreach ($fields as $field) {
                // 补全CASE语句的ELSE部分
                $cases[$field] .= "ELSE `$field` END";
                $updateSql .= $cases[$field] . ", ";
            }
            $updateSql = rtrim($updateSql, ', ');

            // 添加WHERE条件（仅更新当前块的主键）
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $updateSql .= " WHERE `$primaryKey` IN ($placeholders)";
            $bindings = array_merge($bindings, $ids);

            // 在事务中执行带参数绑定的原生查询
            DB::transaction(function () use ($updateSql, $bindings) {
                DB::statement($updateSql, $bindings);
            });
        });
    }


    /**
     * 批量插入数据
     * @param $table
     * @param array $insertArray
     * @param int $chunkSize
     * @return void
     */
    /**
     * 批量插入数据（高效处理大量数据插入）
     *
     * @param string $table 目标表名
     * @param array $insertArray 要插入的数据数组，每个元素应为关联数组
     * @param int $chunkSize 分块大小，默认1000条/块
     * @return void
     * @throws \Exception 当数据格式错误或SQL执行失败时抛出异常
     *
     * 实现原理：
     * 1. 将数据分块处理，避免单条SQL过大
     * 2. 自动提取字段列表（基于第一条数据的键名）
     * 3. 使用参数绑定方式防止SQL注入
     * 4. 在事务中执行保证原子性
     *
     * 示例：
     * $data = [
     *     ['name' => 'John', 'age' => 25],
     *     ['name' => 'Jane', 'age' => 30]
     * ];
     * $batchHelper->batchInsert('users', $data);
     */
    public function batchInsert($table, array $insertArray, int $chunkSize = 1000)
    {
        $data = collect($insertArray);

        $data->chunk($chunkSize)->each(function ($chunk) use ($table) {
            // 自动提取字段列表（取第一个元素的键名）
            $fields = array_keys($chunk->first());

            // 构建参数化占位符和绑定值
            $placeholders = [];
            $bindings = [];

            foreach ($chunk as $datum) {
                $rowValues = [];
                foreach ($fields as $field) {
                    $value = $this->format2SqlValue($datum[$field] ?? null);
                    $rowValues[] = $value;
                }
                $bindings = array_merge($bindings, $rowValues);
                $placeholders[] = '(' . implode(',', array_fill(0, count($fields), '?')) . ')';
            }

            // 构建完整SQL语句
            $columns = implode('`,`', $fields);
            $values = implode(',', $placeholders);
            $sql = "INSERT INTO `$table` (`$columns`) VALUES $values";

            // 事务中执行带参数绑定的原生查询
            DB::transaction(function () use ($sql, $bindings) {
                DB::statement($sql, $bindings);
            });
        });
    }

    // 安全处理SQL值的辅助函数
    private function format2SqlValue($value)
    {
        if (is_null($value)) return null;
        if (is_bool($value)) return (int)$value;
        if (is_array($value)) return json_encode($value, true);
        if ($value instanceof \DateTime || $value instanceof Carbon) return $value->format('Y-m-d H:i:s');
        return $value;
    }
}