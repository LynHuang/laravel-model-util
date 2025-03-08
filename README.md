# 实现laravel eloquent中的常见功能

## 过滤器使用
> 新建过滤器 UserFilter.php
```php
    // UserFilter.php
    namespace App\Filters;
    use LynHuang\LaravelModelUtil\Filter\QueryFilter;

    class UserFilter extends QueryFilter{
        // cname方法名，隐藏数据中的真实字段
        public function cname($value) {
            // 真实字段名为name,默认使用like进行模糊匹配
            $this->analyzeParam('name', 'lk:' . $value);
        }
    }
```
> 在对应的模型中添加Trait，建议直接添加在BaseModel中即可。

```php
    // BaseModel.php
    namespace App\Models;
    use Illuminate\Database\Eloquent\Model;
    use LynHuang\LaravelModelUtil\Traits\UseFilter;
    
    class BaseModel extends Model {
        use UseFilter;
    }
```

> 使用

```php
    // UserController.php
    namespace App\Http\Controllers;
    use App\Models\User;
    use Illuminate\Http\Request;
    use App\Filters\UserFilter;

    class UserController extends Controller {
        // request ['cname' => '黄']
        public function index(Request $request) {
            $filter = new UserFilter($request);
            // 等效sql select * from users where name like '%黄%';
            $users = User::query()->filter($filter)->get(); 
            // 其他处理
            return $users;
        }
    }
```
## 批量更新
```php
    use LynHuang\LaravelModelUtil\Helper\BatchHelper;
    $updates = [
        ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'],
        ['id' => 2, 'name' => 'Bob'], // 只更新名字
        // ...更多记录
    ];

    (new BatchHelper())->batchUpdate('users', $updatas, 1000, 'id');
```

## 批量插入
```php
    use LynHuang\LaravelModelUtil\Helper\BatchHelper;
    $updates = [
        ['name' => 'Alice', 'email' => 'alice@example.com'],
        ['name' => 'Bob'], // 只更新名字
        // ...更多记录
    ];

    (new BatchHelper())->batchInsert('users', $updatas, 1000);
```
