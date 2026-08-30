# 请求过滤器 QueryFilter

> [返回首页](../README.md) · [安装与配置](installation.md)

将 URL 查询参数自动映射为 Eloquent 查询条件。**过滤器方法名即请求参数名**，可隐藏数据库真实字段名。

## 快速开始

```php
<?php
// app/Filters/UserFilter.php
namespace App\Filters;

use LynHuang\LaravelModelUtil\Filter\QueryFilter;

class UserFilter extends QueryFilter
{
    // 请求参数 ?cname=黄 时触发
    public function cname($value)
    {
        // 真实字段为 name，默认使用 like 模糊匹配
        $this->analyzeParam('name', 'lk:' . $value);
    }
}
```

模型中接入 Trait（建议放在 BaseModel）：

```php
<?php
// app/Models/BaseModel.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LynHuang\LaravelModelUtil\Traits\UseFilter;

class BaseModel extends Model
{
    use UseFilter;
}
```

控制器中使用：

```php
<?php
// app/Http/Controllers/UserController.php
namespace App\Http\Controllers;

use App\Filters\UserFilter;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $filter = new UserFilter($request);
        // 等效 SQL: select * from users where name like '%黄%'
        return User::query()->filter($filter)->paginate($filter->getPerPage());
    }
}
```

### 构造方式（Request 与数组均可）

构造函数兼容两种传参：

```php
// 方式一：传入 Request（HTTP 场景推荐），自动读取全部请求参数
$filter = new UserFilter($request);

// 方式二：传入关联数组，便于 CLI / 队列 / 手动指定参数
$filter = new UserFilter(['cname' => '黄', 'status' => 'eq:1']);
```

## 操作符速查表

在过滤器方法中，通过 `analyzeParam($field, $value)` 传值；`$value` 可携带操作符前缀，格式为 `操作符:值`。

| 操作符 | 常量 | 说明 | 示例 |
| --- | --- | --- | --- |
| `gt` | OPTION_GREAT | 大于 | `gt:5` → `> 5` |
| `ge` | OPTION_GREAT_OR_EQUAL | 大于等于 | `ge:5` → `>= 5` |
| `eq` | OPTION_EQUAL | 等于（默认，可不写） | `eq:5` / `5` |
| `ne` | OPTION_NOT_EQUAL | 不等于 | `ne:5` → `<> 5` |
| `lt` | OPTION_LESS | 小于 | `lt:5` → `< 5` |
| `le` | OPTION_LESS_OR_EQUAL | 小于等于 | `le:5` → `<= 5` |
| `bt` | OPTION_BETWEEN | 区间 | `bt:1,10` → `between 1 and 10` |
| `nb` | OPTION_NOT_BETWEEN | 不在区间 | `nb:1,10` → `not between 1 and 10` |
| `in` | OPTION_IN | 在集合内 | `in:1,2,3` |
| `ni` | OPTION_NOT_IN | 不在集合内 | `ni:1,2,3` |
| `lk` | OPTION_LIKE | 模糊匹配 | `lk:黄` → `like '%黄%'` |
| `nl` | OPTION_NULL | 为空 | `nl:1` → `is null` |
| `nn` | OPTION_NOT_NULL | 不为空 | `nn:1` → `is not null` |

JSON 字段查询：直接使用 Laravel 原生 `->` 语法，如 `analyzeParam('data->price', 'gt:5')`。

## 组合条件

同一字段支持 `&&`（与）、`||`（或）组合多个条件：

```php
// 参数 id=gt:5&&lt:10
$this->analyzeParam('id', 'gt:5&&lt:10'); // id > 5 and id < 10

// 参数 id=eq:1||eq:2
$this->analyzeParam('id', 'eq:1||eq:2');  // id = 1 or id = 2
```

> `||` 组合不支持 `in` / `ni` / `bt` / `nb` / `nn` 操作符。

## 声明式过滤（$filterable）

简单字段无需逐个编写过滤方法，声明映射即可自动构建条件：

```php
class ProductFilter extends QueryFilter
{
    protected $filterable = [
        'name'   => 'lk',             // ?name=手机        → name like '%手机%'
        'price'  => ['ge', 'le'],     // ?price=ge:100&&le:500 → 区间；?price=le:500 单边
        'status' => 'in',             // ?status=1,2       → status in (1, 2)
    ];
}
```

规则：

- 映射的键即数据库字段名（也是请求参数名）；参数名需要与字段名不同时，请编写自定义过滤方法。
- 值可带操作符前缀，前缀必须在白名单内，否则抛出 `InvalidParamException`（与排序白名单同一套防注入思路）。
- 不带前缀时默认使用白名单第一个操作符（如 `'name' => 'lk'` 的裸值按 `lk` 处理）。
- 命中映射的参数按映射处理，不再调用同名过滤方法；`&&` / `||` 组合等语法与手动过滤完全一致。

## 内置通用方法

无需自定义，基类已提供：

```php
// 参数 id=gt:5，直接对主键过滤（自动使用模型主键字段）
$this->id('gt:5');

// 参数 created_at=bt:2025-01-01,2025-01-31，按创建时间过滤
$this->created_at('bt:2025-01-01,2025-01-31');

// 快捷时间范围：?created_at=today / yesterday / week / month / year
$this->created_at('today'); // created_at between 今天0点 and 23:59:59
```

**字段名可配置**：`id` 始终使用模型主键（`getKeyName()`），无需配置；`created_at` 过滤与多态关联的 `object_id` 字段可通过子类属性覆盖：

```php
class UserFilter extends QueryFilter
{
    protected $createdAtColumn = 'created_time';  // 覆盖 created_at 字段
    protected $morphIdColumn   = 'target_id';    // 覆盖多态关联字段
}
```

## 排序、分页与随机

**排序**：子类配置 `$sortable` 白名单（未配置则忽略排序），请求参数 `?sort=-created_at,name`（负号倒序，逗号分隔多字段）：

```php
class UserFilter extends QueryFilter
{
    protected $sortable = ['id', 'created_at', 'name'];
}
```

**分页**：`?per_page=20` 自动解析，通过 `getPerPage()` 读取：

```php
return User::query()->filter($filter)->paginate($filter->getPerPage());
```

**随机取 N 条**：请求参数 `?random=10` 直接触发，先取主键 min/max 再随机起点顺序取，避免 `order by random()` 的全表排序（近似随机，适合自增主键）。

> `sort` / `per_page` / `random` 均为基类内置通用方法，请求参数同名即自动触发，无需在子类中定义。`sort` 需要子类配置 `$sortable` 白名单。

## 常用 API

```php
public function foo($value)
{
    // 分析参数并构建查询（支持操作符、组合条件）
    $this->analyzeParam('real_field', $value);

    // 表名前缀模式：real_field 会带上当前表名
    $this->analyzeParam('real_field', $value, true);

    // 多字段模糊搜索，查询间用 or 连接
    $this->searchParam(['name', 'email'], $value);

    // 多字段搜索 + 关联表（[关联模型, 关联过滤器, 关联外键字段]）
    $this->searchParam(['name'], $value, [
        [\App\Models\Company::class, \App\Filters\CompanyFilter::class, 'company_id'],
    ]);

    // 多对多中间表过滤（如标签）
    $this->pivotParam('article_tag', 'tag_id', $value, 'article_id');
}
```

## 关联查询

| 方法 | 用途 | 说明 |
| --- | --- | --- |
| `relateParam(...)` | 一对一/多对一关联过滤 | 根据关联表条件过滤当前表外键 |
| `pivotParam($table, $relate_id, $param, $local_id, $where = [])` | 多对多中间表过滤 | 中间表可附加额外条件 |
| `morphParam($field, $value)` | 多态关联过滤 | 需配置 `model_util.object_types` |
| `searchIds($field, $value, $model, $model_field, $other_condition = [])` | 按关联模型条件过滤 | 依赖模型的 `valid()` 作用域 |
| `searchIdsWithFilter(array $relation, $model, $model_field, $filter, $src_field = 'id')` | 按过滤器过滤关联模型 | 参数从当前 Request 中读取 |
| `searchIdsWithAttrsTable($params, $model, $model_field, $filter, $src_field = 'id')` | 按过滤器过滤属性表 | 参数直接传入数组 |

```php
// 多态关联：?object_type=houses&title=xxx 过滤出对应模型的 id
protected function object_type($value)
{
    $this->morphParam('title', $this->input['title'] ?? null);
}
```

> 关联查询（`relateIdSearch` / `searchIds` / `searchIdsByFilter`）均使用**内联子查询**（`whereIn(field, 子查询)`），由数据库优化器处理，避免把中间结果物化到应用层；结果集可能巨大时建议改用 `whereHas`（exists 短路更优）。

## 动态修改过滤条件

```php
$filter = new UserFilter($request);

$filter->addInput('status', 'eq:1');            // 追加单个条件
$filter->addInputs(['a' => 'x', 'b' => 'y']);   // 追加多个条件
$filter->addInputArray('id', $ids);             // 追加数组条件（默认 in:1,2,3）
$filter->removeInput('status');                 // 移除条件
$filter->clearInput();                          // 清空全部条件
```

## applyAfter 钩子

`apply()` 执行完所有条件后会调用 `applyAfter()`，可复写它追加额外逻辑：

```php
class UserFilter extends QueryFilter
{
    public function applyAfter()
    {
        $this->builder->orderByDesc('created_at');
    }
}
```

## 异常处理

参数格式错误时抛出 `LynHuang\LaravelModelUtil\Exceptions\InvalidParamException`（继承 `\InvalidArgumentException`），与 HTTP 响应解耦，可在全局异常处理器中统一处理：

```php
// app/Exceptions/Handler.php
$this->renderable(function (\LynHuang\LaravelModelUtil\Exceptions\InvalidParamException $e, $request) {
    return response()->json(['message' => $e->getMessage(), 'code' => -1, 'status' => 'error'], 422);
});
```

## 安全性说明

- 所有值均通过 `where` 参数绑定构建，无 SQL 注入风险。
- `apply()` 仅自动调用子类自定义的过滤方法，基类内部方法不会被请求参数触发（如 `?throwError=xxx` 无效）。
- 排序字段必须在 `$sortable` 白名单内，未配置白名单时忽略排序。
- 非标量（数组等）请求值会被安全忽略。
