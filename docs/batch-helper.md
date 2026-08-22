# 批量操作 BatchHelper

> [返回首页](../README.md) · [安装与配置](installation.md)

使用原生 SQL 实现高性能批量操作，自动分块、参数绑定、事务执行，自动拼接数据库表前缀，所有方法均返回受影响的行数。

## 批量更新

使用 `CASE WHEN` 单条 SQL 更新多行，仅更新传入的字段（主键外的字段）：

```php
use LynHuang\LaravelModelUtil\Helper\BatchHelper;

$updates = [
    ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'],
    ['id' => 2, 'name' => 'Bob'],   // 只更新 name，email 不变
    // ...更多记录
];

$affected = (new BatchHelper())->batchUpdate('users', $updates, 1000, 'id');
// 返回受影响的行数
```

默认会自动写入 `updated_at`（可关闭或改名，见参数说明）。

## 批量插入

```php
use LynHuang\LaravelModelUtil\Helper\BatchHelper;

$rows = [
    ['name' => 'Alice', 'email' => 'alice@example.com'],
    ['name' => 'Bob'],  // 缺少的字段自动填 null
    // ...更多记录
];

$affected = (new BatchHelper())->batchInsert('users', $rows, 1000);
// 返回插入的行数
```

## 批量插入或更新（upsert）

按唯一键判断冲突，MySQL 走 `ON DUPLICATE KEY UPDATE`，PostgreSQL / SQLite 走 `ON CONFLICT`：

```php
$affected = (new BatchHelper())->batchUpsert('users', [
    ['email' => 'alice@example.com', 'name' => 'Alice'],
    ['email' => 'bob@example.com',   'name' => 'Bob'],
], ['email'], ['name']);
// 第三个参数为唯一键字段，第四个参数为冲突时更新的字段（缺省更新全部非唯一键字段）
```

## 批量删除 / 软删除 / 恢复

```php
$deleted = (new BatchHelper())->batchDelete('users', [1, 2, 3]);

// 软删除（deleted_at 置为当前时间）与恢复
$deleted = (new BatchHelper())->batchSoftDelete('users', [1, 2]);
$restored = (new BatchHelper())->batchRestore('users', [1, 2]);
```

## 参数说明

| 参数 | 默认值 | 说明 |
| --- | --- | --- |
| `$table` | - | 表名（无需手动加前缀） |
| `$chunkSize` | 100 / 1000 | 每批处理的行数，防止 SQL 过大 |
| `$primaryKey` | `id` | 批量操作的主键字段 |
| `$timestamps` | `true` | `batchUpdate` 是否自动写入更新时间 |
| `$updatedAtColumn` | `updated_at` | `batchUpdate` 的更新时间字段名 |
| `$deletedAtColumn` | `deleted_at` | `batchSoftDelete` / `batchRestore` 的软删除字段名 |
| `$connection` | `null` | 数据库连接名，`null` 使用默认连接 |

## 通用约定

- 空数组直接返回 `0`，不会执行 SQL。
- 每批数据在独立事务中执行，单批失败自动回滚。
- 表名自动拼接 `DB::getTablePrefix()` 前缀。
- 数据字段不一致时自动取所有行的字段并集，缺失字段填 `null`。
- 所有方法支持指定数据库连接（最后一个参数）：`(new BatchHelper())->batchInsert('users', $rows, 1000, 'pgsql_read')`；未指定时使用默认连接。
- 标识符引用符按驱动自动适配：PostgreSQL 使用双引号，MySQL / MariaDB / SQLite 使用反引号。
