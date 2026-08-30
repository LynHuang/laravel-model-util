# 安装与配置

> [返回首页](../README.md)

## 安装

```bash
composer require lyn_huang/laravel-model-util
```

安装后 Laravel 会自动发现并注册 `ModelUtilServiceProvider`（通过 `extra.laravel.providers` 自动发现），无需手动注册。

## 发布配置文件（可选）

```bash
php artisan vendor:publish --tag=model-util-config
```

发布后生成 `config/model_util.php`：

```php
<?php

return [
    // 多态类型与模型的映射（用于多态关联过滤）
    'object_types' => [
        'houses' => \App\Models\House::class,
        'shops'  => \App\Models\Shop::class,
    ],

    // 操作审计日志表名（RecordsActivity 使用）
    'activity_logs_table' => 'activity_logs',

    // 操作审计 diff 排除的敏感字段（RecordsActivity 使用）
    'activity_excludes' => ['password', 'password_confirmation', 'remember_token', 'updated_at'],

    // 过滤器脚手架（make:filter 使用）
    'filters' => [
        'directory' => 'Filters',   // 生成目录，相对 app/ 路径，支持子目录
    ],

    // SQL 日志（生产环境强制关闭）
    'sql_log' => [
        'enabled' => false,   // 是否开启
        'channel' => null,    // 日志通道，null 使用默认
        'level'   => 'debug', // 日志级别：debug / info / warning / error
        'slow_ms' => 0,       // 慢查询阈值（毫秒），>0 时只记录超阈值语句（warning 级别）
    ],
];
```

> 未发布配置文件时，包内部已合并默认配置，`config('model_util.*')` 依然可用，默认值见上表。

## 各配置项详解

### `object_types`

多态关联过滤（`morphParam`）依赖的类型 → 模型映射。当请求参数携带 `object_type` 时，`QueryFilter::getModelByType()` 会根据该配置解析出对应模型类。

若映射规则更复杂，可复写过滤器中的 `getModelByType()` 方法：

```php
class CommentFilter extends QueryFilter
{
    protected function getModelByType($type)
    {
        // 自定义解析逻辑
        return match ($type) {
            'houses' => \App\Models\House::class,
            default  => null,
        };
    }
}
```

### `activity_logs_table`

`RecordsActivity` Trait 写入操作日志的表名，默认 `activity_logs`。

### `activity_excludes`

`RecordsActivity` 记录更新前后 diff（`properties`）时排除的字段，避免密码等敏感信息明文写入审计日志。模型可通过 `$activityExcludes` 属性追加自己的排除项（与该配置合并生效）：

```php
class Member extends Model
{
    use RecordsActivity;
    protected $activityExcludes = ['id_card'];
}
```

### `sql_log`

通过 Laravel 日志系统记录每次执行的 SQL（不落数据库）。`enabled` 为 `true` 时开启；**生产环境（`app.env = production`）强制关闭**，即使 `enabled` 为 `true` 也不记录，防止敏感 SQL 泄露到日志。

记录内容：SQL 语句、绑定参数、执行耗时（ms）、连接名。

`slow_ms` 大于 0 时进入慢查询模式：只记录耗时超过阈值的语句，级别固定为 `warning`（适合常开不刷屏）；默认 `0` 记录全部 SQL，级别取 `level` 配置。

### `filters`

`make:filter` 脚手架的生成配置：

- `directory`：过滤器类的生成目录，相对 `app/` 路径，默认 `Filters`；支持子目录（如 `Admin/Filters` → `app/Admin/Filters`），类的命名空间随目录同步变化。

## 自定义 stub 模板（可选）

`make:filter` 生成的骨架来自包内 `stubs/filter.stub`，可发布到项目中修改：

```bash
php artisan vendor:publish --tag=model-util-stubs
```

发布后生成 `stubs/model-util/filter.stub`，命令会优先使用该文件（不存在时回退到包内默认模板）。

## 发布迁移文件（可选）

使用操作审计（`RecordsActivity`）时需要：

```bash
php artisan vendor:publish --tag=model-util-migrations
php artisan migrate
```

迁移会创建 `activity_logs` 表（id、log_name、description、subject_type、subject_id、causer_type、causer_id、properties、时间戳）。

## 开发与测试

```bash
composer install
composer test   # 等价于 phpunit
```

测试基于 `orchestra/testbench`，使用内存 SQLite，无需额外配置数据库（需 PHP 已启用 `pdo_sqlite` / `sqlite3` 扩展）。

> 本地 PHP 版本较低（如 7.4）时，`composer install` 会自动选择兼容的依赖组合（Laravel 8.x）；若 lock 文件提示不同步，运行 `composer update --lock` 即可。
