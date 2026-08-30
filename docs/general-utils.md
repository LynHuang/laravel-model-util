# 通用辅助

> [返回首页](../README.md) · [安装与配置](installation.md)

## 路由搜索 route:search

按关键字模糊搜索路由（匹配 URI / 路由名称 / 控制器动作，大小写不敏感），表格输出请求方式、URI 与控制器方法：

```bash
php artisan route:search user            # 关键字模糊匹配
php artisan route:search order --method=POST   # 按请求方式过滤
php artisan route:search                 # 不带关键字列出全部路由
```

输出示例：

```
+------------+-------------+------------------------------------+
| 请求方式   | URI         | 控制器@方法                         |
+------------+-------------+------------------------------------+
| GET|HEAD   | api/users   | App\Http\Controllers\UserController@index |
+------------+-------------+------------------------------------+
共找到 1 条路由
```

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

### 异常转统一响应

配合全局异常渲染，把异常转换为统一失败响应（`InvalidParamException` 固定 code 422，`HttpException` 取 HTTP 状态码，其余用兜底 code）：

```php
// Laravel 11/12 在 bootstrap/app.php 的 withExceptions 中注册，
// Laravel 8/9/10 在 app/Exceptions/Handler.php 中注册 renderable：
$exceptions->render(function (InvalidParamException $e, Request $request) {
    return response()->json(ApiResponse::fromThrowable($e));
});
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

更省心的闭环写法 `execute()`：首次请求执行业务，重复请求抛 `DuplicateRequestException`，业务异常时自动释放幂等键（允许重试）：

```php
use LynHuang\LaravelModelUtil\Exceptions\DuplicateRequestException;

try {
    $result = IdempotentHelper::execute($key, function () {
        return Order::create([...]);   // 业务逻辑，返回值透传
    }, 60);
} catch (DuplicateRequestException $e) {
    return ApiResponse::fail('请勿重复提交');
}
```

> 底层使用 `Cache::add()`（原子操作），缓存驱动需支持原子写入（redis / memcached / 默认驱动均可）。

## 经纬度距离 GeoHelper

```php
use LynHuang\LaravelModelUtil\Helper\GeoHelper;

// 计算两点间球面距离（Haversine 公式，单位公里）
$km = GeoHelper::distance(31.23, 121.47, 39.90, 116.40);   // 上海-北京约 1067km

// 附近查询：过滤半径内记录，并附带 distance 字段
// 内部先用经纬度矩形范围粗筛（lat/lng 上有索引时可大幅减少计算量），再做精确球面距离过滤
Shop::query()->near($lat, $lng, 5)->orderBy('distance')->get();

// 按距离由近到远排序（可独立使用，也可与 near 组合）
Shop::query()->near($lat, $lng, 5)->orderByDistance($lat, $lng)->get();
```

作用域参数：`near($lat, $lng, $radiusKm, $latColumn = 'lat', $lngColumn = 'lng')`，经纬度字段名可自定义。

> 性能提示：给 `lat` / `lng` 建普通索引即可命中粗筛范围；跨越 ±180 度经线的选点（如太平洋上）粗筛不会回卷，需自行拆分查询。

## SQL 日志

通过 Laravel 日志系统记录每次执行的 SQL（不落数据库），用于开发调试：

```php
// config/model_util.php
'sql_log' => [
    'enabled' => true,
    'channel' => 'daily',  // 指定日志通道，null 使用默认通道
    'level'   => 'debug',  // 日志级别：debug / info / warning / error
    'slow_ms' => 0,        // 慢查询阈值（毫秒），0 表示记录全部 SQL
],
```

- 记录内容：SQL 语句、绑定参数、执行耗时（ms）、连接名。
- **慢查询模式**：`slow_ms` 大于 0 时只记录耗时超过阈值的语句，级别固定为 `warning`，适合常开不刷屏。
- **生产环境自动失效**：当 `app.env = production` 时，即使 `enabled` 为 `true` 也不记录，防止敏感 SQL 泄露到日志。
- 运行时动态判断开关，开发环境可随时修改配置生效。
