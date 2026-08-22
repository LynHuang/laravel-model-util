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
        'level' => 'debug',
    ],
];
