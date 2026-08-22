# 通用辅助

> [返回首页](../README.md) · [安装与配置](installation.md)

## 统一响应 ApiResponse

统一返回 `['code' => 0, 'message' => 'ok', 'data' => ...]` 结构，控制器中直接 return 即可：

```php
use LynHuang\LaravelModelUtil\Support\ApiResponse;

return ApiResponse::success($data);                 // ['code'=>0, 'message'=>'ok', 'data'=>...]
return ApiResponse::fail('参数错误', 422);           // ['code'=>422, 'message'=>'参数错误', 'data'=>null]
return ApiResponse::paginate($paginator);           // 分页数据 + meta（current_page/last_page/total...）
```

分页响应结构：

```php
[
    'code' => 0,
    'message' => 'ok',
    'data' => [
        'items' => [...],                          // 当前页数据
        'meta'  => [
            'current_page' => 1,
            'last_page'    => 10,
            'per_page'     => 15,
            'total'        => 150,
        ],
    ],
]
```

## 防重复提交 IdempotentHelper

基于原子缓存操作，首次成功、ttl 内重复失败：

```php
use LynHuang\LaravelModelUtil\Helper\IdempotentHelper;

$key = "submit:{$user->id}:{$order->id}";   // 幂等键建议包含用户 id 与业务 id

if (IdempotentHelper::isDuplicate($key, 60)) {
    return ApiResponse::fail('请勿重复提交');
}

// ...业务处理

IdempotentHelper::release($key);   // 业务完成后主动释放，允许再次提交
```

> 底层使用 `Cache::add()`（原子操作），缓存驱动需支持原子写入（redis / memcached / 默认驱动均可）。

## 经纬度距离 GeoHelper

```php
use LynHuang\LaravelModelUtil\Helper\GeoHelper;

// 计算两点间球面距离（Haversine 公式，单位公里）
$km = GeoHelper::distance(31.23, 121.47, 39.90, 116.40);   // 上海-北京约 1067km

// 附近查询：过滤半径内记录，并附带 distance 字段
Shop::query()->near($lat, $lng, 5)->orderBy('distance')->get();
```

`near()` 作用域参数：`near($lat, $lng, $radiusKm, $latColumn = 'lat', $lngColumn = 'lng')`，经纬度字段名可自定义。

## SQL 日志

通过 Laravel 日志系统记录每次执行的 SQL（不落数据库），用于开发调试：

```php
// config/model_util.php
'sql_log' => [
    'enabled' => true,
    'channel' => 'daily',  // 指定日志通道，null 使用默认通道
    'level'   => 'debug',  // 日志级别：debug / info / warning / error
],
```

- 记录内容：SQL 语句、绑定参数、执行耗时（ms）、连接名。
- **生产环境自动失效**：当 `app.env = production` 时，即使 `enabled` 为 `true` 也不记录，防止敏感 SQL 泄露到日志。
- 运行时动态判断开关，开发环境可随时修改配置生效。
