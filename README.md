# Laravel Model Util

Laravel 常用模型工具集：封装一些**常用、但 Laravel 本身没有或实现起来麻烦**的能力，开箱即用。

## 特性

- **请求过滤器 QueryFilter**：请求参数自动映射为 Eloquent 查询条件，支持操作符、`&&` / `||` 组合、声明式白名单过滤、排序、分页、时间范围、随机取 N 条及关联表过滤。
- **批量操作 BatchHelper**：批量更新（`CASE WHEN`）、批量插入、批量插入或更新（upsert）、批量删除、批量软删除 / 恢复；`BatchHelper::for(Model::class)` 模型版自动解析表名 / 主键 / 时间戳。
- **模型层工具**：树形结构、模型树查询、排序重排（支持分组）、多语言字段、字段加解密 / 脱敏、操作审计、状态机（含流转钩子）、乐观锁、计数缓存、唯一编号生成。
- **通用辅助**：统一 API 响应（含异常转统一响应）、防重复提交（幂等执行）、经纬度距离与附近查询、慢查询日志；`make:filter` 过滤器脚手架与 `route:search` 路由模糊搜索命令。
- **模型 Trait（UseFilter）**：一行接入 `->filter()` 作用域。

## 环境要求

- PHP >= 7.3
- Laravel 8.x ~ 12.x

## 安装

```bash
composer require lyn_huang/laravel-model-util
```

安装后 Laravel 会自动发现并注册 `ModelUtilServiceProvider`，无需手动配置。

## 配置（可选）

发布配置文件：

```bash
php artisan vendor:publish --tag=model-util-config
```

发布后生成 `config/model_util.php`，可配置多态关联映射、操作审计表名与排除字段、SQL 日志、`make:filter` 生成目录等（各配置项详解见 [安装与配置](docs/installation.md)）。

> 不发布也能用——包内部已合并默认配置，`config('model_util.*')` 直接可读。使用操作审计（RecordsActivity）时还需 `php artisan vendor:publish --tag=model-util-migrations`；`make:filter` 的骨架模板可用 `--tag=model-util-stubs` 发布后自定义。

## 快速上手

以请求过滤器为例，三步接入：

**1. 新建过滤器类**（`php artisan make:filter UserFilter` 可生成骨架，生成目录与骨架模板均可配置；方法名即请求参数名，可隐藏数据库真实字段名）：

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

**2. 在模型中接入 Trait**（建议放在 BaseModel）：

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

**3. 控制器中使用**：

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
        // 传入 $request 自动读取全部请求参数（推荐，HTTP 场景）
        $filter = new UserFilter($request);
        // 等效 SQL: select * from users where name like '%黄%'
        return User::query()->filter($filter)->paginate($filter->getPerPage());
    }
}
```

> `QueryFilter` 构造函数兼容两种传参：`new UserFilter($request)`（HTTP 场景，自动读取全部请求参数）或 `new UserFilter(['cname' => '黄'])`（数组，便于 CLI / 队列 / 手动指定参数）。

## 文档导航

| 文档 | 内容 |
| --- | --- |
| [安装与配置](docs/installation.md) | 配置文件发布、`config/model_util.php` 各选项详解、迁移发布 |
| [请求过滤器 QueryFilter](docs/query-filter.md) | 操作符速查表、声明式过滤、组合条件、排序分页随机、关联过滤、动态参数、异常处理 |
| [批量操作 BatchHelper](docs/batch-helper.md) | 模型版操作、批量更新 / 插入 / upsert / 删除 / 软删除恢复、参数说明 |
| [模型层工具](docs/model-tools.md) | 树形结构、模型树、排序重排、多语言、字段加解密 / 脱敏、操作审计、状态机、乐观锁、计数缓存、唯一编号 |
| [通用辅助](docs/general-utils.md) | 统一响应、防重复提交、经纬度距离、SQL 日志、路由搜索命令 |

## License

MIT（`composer.json` 已声明；如需 LICENSE 文件可后续补充）
