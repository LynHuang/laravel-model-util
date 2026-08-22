<?php

namespace LynHuang\LaravelModelUtil\Helper;

use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class BatchHelper
{
    /**
     * 批量更新数据（使用CASE WHEN语句实现高效批量更新）
     *
     * @param string $table 需要更新的表名
     * @param array $updateArray 需要更新的数据数组，每个元素必须包含主键字段
     * @param int $chunkSize 分块处理的大小，默认100条/块
     * @param string $primaryKey 主键字段名称，默认'id'
     * @param bool $timestamps 是否自动写入更新时间，默认 true
     * @param string $updatedAtColumn 更新时间字段名称，默认'updated_at'
     * @param string|null $connection 数据库连接名，null 使用默认连接
     * @return int 受影响的行数
     * @throws \InvalidArgumentException 当数据缺少主键或没有可更新字段时抛出异常
     *
     * 实现原理：
     * 1. 将数据分块处理，避免单条SQL过大
     * 2. 使用CASE WHEN条件语句构建批量更新
     * 3. 参数绑定方式防止SQL注入
     * 4. 在事务中执行保证原子性
     */
    public function batchUpdate($table, array $updateArray, int $chunkSize = 100, string $primaryKey = 'id', bool $timestamps = true, string $updatedAtColumn = 'updated_at', $connection = null)
    {
        if (empty($updateArray)) {
            return 0;
        }

        // 自动维护时间戳
        if ($timestamps) {
            $now = Carbon::now()->format('Y-m-d H:i:s');
            foreach ($updateArray as &$row) {
                if (is_array($row)) {
                    $row[$updatedAtColumn] = $now;
                }
            }
            unset($row);
        }

        $conn = DB::connection($connection);
        $q = $this->quoteChar($conn->getDriverName());
        $table = $this->tableName($table, $conn);
        $affected = 0;

        collect($updateArray)->chunk($chunkSize)->each(function ($chunk) use ($conn, $table, $primaryKey, $q, &$affected) {
            $cases = [];    // 存储各个字段的CASE语句
            $bindings = []; // 参数绑定值
            $ids = [];      // 当前块涉及的主键集合

            // 提取所有需要更新的字段（排除主键）
            $fields = array_diff(array_keys($chunk->first()), [$primaryKey]);

            // 没有可更新的字段则跳过当前块，避免生成非法SQL
            if (empty($fields)) {
                return;
            }

            // 初始化每个字段的CASE语句开头
            foreach ($fields as $field) {
                $cases[$field] = "$q$field$q = CASE ";
            }

            // 校验主键并收集当前块的主键集合
            $ids = [];
            foreach ($chunk as $datum) {
                if (!isset($datum[$primaryKey])) {
                    throw new \InvalidArgumentException("Missing primary key '$primaryKey' in update data");
                }
                $ids[] = $datum[$primaryKey];
            }

            // 按列（字段）构建 CASE WHEN 与绑定参数
            // 注意：SQL 中每个字段的 CASE 横跨所有行，绑定参数必须按"字段→行"的顺序排列
            foreach ($fields as $field) {
                foreach ($chunk as $datum) {
                    $cases[$field] .= "WHEN $q$primaryKey$q = ? THEN ? ";
                    $bindings[] = $datum[$primaryKey];
                    $bindings[] = $this->format2SqlValue($datum[$field] ?? null);
                }
            }

            // 组合完整SQL语句
            $updateSql = "UPDATE $q$table$q SET ";
            foreach ($fields as $field) {
                // 补全CASE语句的ELSE部分
                $cases[$field] .= "ELSE $q$field$q END";
                $updateSql .= $cases[$field] . ", ";
            }
            $updateSql = rtrim($updateSql, ', ');

            // 添加WHERE条件（仅更新当前块的主键）
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $updateSql .= " WHERE $q$primaryKey$q IN ($placeholders)";
            $bindings = array_merge($bindings, $ids);

            // 在事务中执行带参数绑定的原生查询
            $affected += $conn->transaction(function () use ($conn, $updateSql, $bindings) {
                return $conn->affectingStatement($updateSql, $bindings);
            });
        });

        return $affected;
    }

    /**
     * 批量插入数据（高效处理大量数据插入）
     *
     * @param string $table 目标表名
     * @param array $insertArray 要插入的数据数组，每个元素应为关联数组
     * @param int $chunkSize 分块大小，默认1000条/块
     * @param string|null $connection 数据库连接名，null 使用默认连接
     * @return int 插入的行数
     *
     * 实现原理：
     * 1. 将数据分块处理，避免单条SQL过大
     * 2. 自动提取字段列表（取所有行的字段并集，避免字段不一致时丢失数据）
     * 3. 使用参数绑定方式防止SQL注入
     * 4. 在事务中执行保证原子性
     */
    public function batchInsert($table, array $insertArray, int $chunkSize = 1000, $connection = null)
    {
        if (empty($insertArray)) {
            return 0;
        }

        $conn = DB::connection($connection);
        $q = $this->quoteChar($conn->getDriverName());
        $table = $this->tableName($table, $conn);
        $affected = 0;

        collect($insertArray)->chunk($chunkSize)->each(function ($chunk) use ($conn, $table, $q, &$affected) {
            // 提取所有行的字段并集，保证字段不统一的记录也不丢数据
            $fields = [];
            foreach ($chunk as $datum) {
                $fields = array_values(array_unique(array_merge($fields, array_keys($datum))));
            }

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

            // 构建完整SQL语句（列名已按驱动引用符包裹）
            $columns = implode("$q,$q", $fields);
            $values = implode(',', $placeholders);
            $sql = "INSERT INTO $q$table$q ($q$columns$q) VALUES $values";

            // 事务中执行带参数绑定的原生查询
            $conn->transaction(function () use ($conn, $sql, $bindings) {
                $conn->statement($sql, $bindings);
            });

            $affected += count($chunk);
        });

        return $affected;
    }

    /**
     * 批量插入或更新（upsert）
     *
     * MySQL 使用 ON DUPLICATE KEY UPDATE，PostgreSQL / SQLite 使用 ON CONFLICT。
     *
     * @param string $table 目标表名
     * @param array $rows 要插入的数据数组
     * @param array $uniqueBy 唯一键字段（用于判断冲突）
     * @param array $updateColumns 冲突时需要更新的字段，为空时更新全部非唯一键字段
     * @param int $chunkSize 分块大小，默认1000条/块
     * @param string|null $connection 数据库连接名，null 使用默认连接
     * @return int 受影响的行数
     */
    public function batchUpsert($table, array $rows, array $uniqueBy, array $updateColumns = [], int $chunkSize = 1000, $connection = null)
    {
        if (empty($rows)) {
            return 0;
        }
        if (empty($uniqueBy)) {
            throw new \InvalidArgumentException('uniqueBy must not be empty for batchUpsert');
        }

        $conn = DB::connection($connection);
        $driver = $conn->getDriverName();
        $q = $this->quoteChar($driver);
        $table = $this->tableName($table, $conn);
        $affected = 0;

        collect($rows)->chunk($chunkSize)->each(function ($chunk) use ($conn, $table, $uniqueBy, $updateColumns, $driver, $q, &$affected) {
            // 提取所有行的字段并集
            $fields = [];
            foreach ($chunk as $datum) {
                $fields = array_values(array_unique(array_merge($fields, array_keys($datum))));
            }

            $bindings = [];
            $placeholders = [];
            foreach ($chunk as $datum) {
                $rowValues = [];
                foreach ($fields as $field) {
                    $rowValues[] = $this->format2SqlValue($datum[$field] ?? null);
                }
                $bindings = array_merge($bindings, $rowValues);
                $placeholders[] = '(' . implode(',', array_fill(0, count($fields), '?')) . ')';
            }

            // 冲突时需要更新的字段
            $updateFields = $updateColumns ?: array_diff($fields, $uniqueBy);
            if (empty($updateFields)) {
                $updateFields = $fields;
            }

            $quoted = function ($f) use ($q) {
                return $q . $f . $q;
            };

            $columns = implode(',', array_map($quoted, $fields));
            $values = implode(',', $placeholders);
            $sql = "INSERT INTO $q$table$q ($columns) VALUES $values ";

            if ($driver === 'pgsql' || $driver === 'sqlite') {
                $conflict = implode(',', array_map($quoted, $uniqueBy));
                $set = implode(', ', array_map(function ($f) use ($quoted) {
                    return $quoted($f) . ' = EXCLUDED.' . $quoted($f);
                }, $updateFields));
                $sql .= "ON CONFLICT ($conflict) DO UPDATE SET $set";
            } else {
                // MySQL 8.0.20+ 会提示 VALUES() 弃用，但功能正常且兼容老版本
                $set = implode(', ', array_map(function ($f) use ($quoted) {
                    return $quoted($f) . ' = VALUES(' . $quoted($f) . ')';
                }, $updateFields));
                $sql .= "ON DUPLICATE KEY UPDATE $set";
            }

            $affected += $conn->transaction(function () use ($conn, $sql, $bindings) {
                return $conn->affectingStatement($sql, $bindings);
            });
        });

        return $affected;
    }

    /**
     * 按主键批量删除
     *
     * @param string $table 目标表名
     * @param array $ids 主键值数组
     * @param int $chunkSize 分块大小，默认1000条/块
     * @param string $primaryKey 主键字段名称，默认'id'
     * @param string|null $connection 数据库连接名，null 使用默认连接
     * @return int 删除的行数
     */
    public function batchDelete($table, array $ids, int $chunkSize = 1000, string $primaryKey = 'id', $connection = null)
    {
        if (empty($ids)) {
            return 0;
        }
        $conn = DB::connection($connection);
        $table = $this->tableName($table, $conn);
        $affected = 0;

        collect($ids)->chunk($chunkSize)->each(function ($chunk) use ($conn, $table, $primaryKey, &$affected) {
            $affected += $conn->table($table)->whereIn($primaryKey, $chunk->all())->delete();
        });

        return $affected;
    }

    /**
     * 批量软删除（将 deleted_at 置为当前时间）
     *
     * @param string $table 目标表名
     * @param array $ids 主键值数组
     * @param int $chunkSize 分块大小，默认1000条/块
     * @param string $primaryKey 主键字段名称，默认'id'
     * @param string $deletedAtColumn 软删除时间字段，默认'deleted_at'
     * @param string|null $connection 数据库连接名，null 使用默认连接
     * @return int 受影响的行数
     */
    public function batchSoftDelete($table, array $ids, int $chunkSize = 1000, string $primaryKey = 'id', string $deletedAtColumn = 'deleted_at', $connection = null)
    {
        if (empty($ids)) {
            return 0;
        }
        $conn = DB::connection($connection);
        $table = $this->tableName($table, $conn);
        $affected = 0;
        $now = Carbon::now()->format('Y-m-d H:i:s');

        collect($ids)->chunk($chunkSize)->each(function ($chunk) use ($conn, $table, $primaryKey, $deletedAtColumn, $now, &$affected) {
            $affected += $conn->table($table)->whereIn($primaryKey, $chunk->all())->update([$deletedAtColumn => $now]);
        });

        return $affected;
    }

    /**
     * 批量恢复软删除（将 deleted_at 置空）
     *
     * @param string $table 目标表名
     * @param array $ids 主键值数组
     * @param int $chunkSize 分块大小，默认1000条/块
     * @param string $primaryKey 主键字段名称，默认'id'
     * @param string $deletedAtColumn 软删除时间字段，默认'deleted_at'
     * @param string|null $connection 数据库连接名，null 使用默认连接
     * @return int 受影响的行数
     */
    public function batchRestore($table, array $ids, int $chunkSize = 1000, string $primaryKey = 'id', string $deletedAtColumn = 'deleted_at', $connection = null)
    {
        if (empty($ids)) {
            return 0;
        }
        $conn = DB::connection($connection);
        $table = $this->tableName($table, $conn);
        $affected = 0;

        collect($ids)->chunk($chunkSize)->each(function ($chunk) use ($conn, $table, $primaryKey, $deletedAtColumn, &$affected) {
            $affected += $conn->table($table)->whereIn($primaryKey, $chunk->all())->update([$deletedAtColumn => null]);
        });

        return $affected;
    }

    // 拼接数据库表前缀
    private function tableName($table, Connection $conn)
    {
        $prefix = $conn->getTablePrefix();
        if ($prefix !== '' && strpos($table, $prefix) !== 0) {
            return $prefix . $table;
        }
        return $table;
    }

    // 根据驱动返回标识符引用符：PostgreSQL 用双引号，其余（MySQL/MariaDB/SQLite）用反引号
    private function quoteChar($driver)
    {
        return $driver === 'pgsql' ? '"' : '`';
    }

    // 安全处理SQL值的辅助函数
    private function format2SqlValue($value)
    {
        if (is_null($value)) return null;
        if (is_bool($value)) return (int)$value;
        if (is_array($value)) return json_encode($value, JSON_UNESCAPED_UNICODE);
        if ($value instanceof \DateTime || $value instanceof Carbon) return $value->format('Y-m-d H:i:s');
        return $value;
    }
}
