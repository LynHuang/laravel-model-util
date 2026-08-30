<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 多态类型与模型映射
    |--------------------------------------------------------------------------
    |
    | QueryFilter::getModelByType() 会根据 object_type 解析对应的模型类名，
    | 用于 morphParam() 多态关联查询。
    |
    | 示例：
    | 'object_types' => [
    |     'houses' => \App\Models\House::class,
    |     'shops'  => \App\Models\Shop::class,
    | ],
    |
    */
    'object_types' => [],

    /*
    |--------------------------------------------------------------------------
    | 操作审计日志表名
    |--------------------------------------------------------------------------
    |
    | RecordsActivity Trait 写入的活动日志表名，
    | 可通过 vendor:publish --tag=model-util-migrations 发布迁移文件。
    |
    */
    'activity_logs_table' => 'activity_logs',

    /*
    |--------------------------------------------------------------------------
    | 操作审计排除字段
    |--------------------------------------------------------------------------
    |
    | RecordsActivity 记录更新前后 diff（properties）时排除的字段，
    | 避免密码等敏感信息明文写入审计日志。
    | 模型可通过 $activityExcludes 属性追加各自的排除字段（与该配置合并生效）。
    |
    */
    'activity_excludes' => ['password', 'password_confirmation', 'remember_token', 'updated_at'],

    /*
    |--------------------------------------------------------------------------
    | SQL 日志
    |--------------------------------------------------------------------------
    |
    | 通过 Laravel 日志系统记录每次执行的 SQL，不写入数据库。
    | enabled 开启后，生产环境（app.env = production）强制关闭，
    | 防止敏感 SQL 语句泄露到日志文件。
    |
    */
    'sql_log' => [
        // 是否开启 SQL 日志
        'enabled' => false,

        // 日志通道，null 表示使用默认通道
        'channel' => null,

        // 日志级别：debug / info / warning / error
        // 配置 slow_ms 后自动升级为 warning（慢查询模式）
        'level' => 'debug',

        // 慢查询阈值（毫秒），大于 0 时只记录耗时超过该值的语句（级别固定 warning）
        'slow_ms' => 0,
    ],
];
