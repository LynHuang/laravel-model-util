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
    public function batchUpdate($table, array $updateArray, int $chunkSize = 100, string $primaryKey = 'id')
    {
        $data = collect($updateArray);

        $data->chunk($chunkSize)->each(function ($chunk) use ($table, $primaryKey) {
            // 初始化SQL组件
            $cases = [];
            $bindings = [];
            $ids = [];

            // 提取所有需要更新的字段（排除主键）
            $fields = array_keys($chunk->first());
            $fields = array_diff($fields, [$primaryKey]);

            foreach ($fields as $field) {
                $cases[$field] = "`$field` = CASE ";
            }

            // 构建CASE WHEN语句和绑定参数
            foreach ($chunk as $datum) {
                foreach ($fields as $field) {
                    $value = $datum[$field] ?? null;
                    $cases[$field] .= "WHEN `$primaryKey` = ? THEN ? ";
                    $bindings[] = $datum[$primaryKey]; // 主键值
                    $bindings[] = $this->format2SqlValue($value); // 字段值
                }
                $ids[] = $datum[$primaryKey];
            }

            // 组合完整SQL语句
            $updateSql = "UPDATE `$table` SET ";
            foreach ($fields as $field) {
                $cases[$field] .= "ELSE `$field` END";
                $updateSql .= $cases[$field] . ", ";
            }
            $updateSql = rtrim($updateSql, ', ');

            // 添加WHERE条件
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $updateSql .= " WHERE `$primaryKey` IN ($placeholders)";
            $bindings = array_merge($bindings, $ids);

            // 执行带参数绑定的原生查询（防止SQL注入）
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