# 模型层工具

> [返回首页](../README.md) · [安装与配置](installation.md)

## 树形结构 TreeHelper

适用于栏目、菜单、分类等父子数据：

```php
use LynHuang\LaravelModelUtil\Helper\TreeHelper;

$tree = TreeHelper::toTree($items);                          // 扁平数组 → 树（O(n)）
$flat = TreeHelper::flatten($tree);                          // 树 → 扁平数组
$ids  = TreeHelper::descendants($items, 1, true);            // 节点 1 的所有后代 id（含自身）
$ids  = TreeHelper::ancestors($items, 4);                    // 节点 4 的所有祖先 id（父级、祖父级...）
```

字段名可通过参数自定义：`toTree($items, $idKey = 'id', $parentKey = 'parent_id', $childrenKey = 'children', $rootId = null)`。

## 排序重排 Sortable

基于 `weight` 字段（可覆盖）提供拖拽排序：

```php
use LynHuang\LaravelModelUtil\Traits\Sortable;

class Menu extends Model
{
    use Sortable;
    protected $sortColumn = 'weight';   // 排序字段，可覆盖
}

$menu->moveUp();              // 上移
$menu->moveDown();            // 下移
$menu->moveToTop();           // 置顶
$menu->moveToBottom();        // 置底
Menu::query()->reorder([3, 1, 2]);  // 按主键顺序整体重排

Menu::query()->ordered()->get();     // 按排序字段升序查询（scope）
```

## 多语言字段 Translatable

翻译内容以 JSON 存于单字段（建议配合 `$casts`）：

```php
use LynHuang\LaravelModelUtil\Traits\Translatable;

class Article extends Model
{
    use Translatable;
    protected $casts = ['title' => 'array'];
}

$article->translate('title', 'zh');             // 读取中文标题（缺省时回退 fallback_locale）
$article->setTranslation('title', 'en', 'Hello')->save();
$article->hasTranslation('title', 'en');          // 是否已有英文翻译
```

## 字段加解密 EncryptsAttributes

模型事件自动加密存储、解密读取，支持精确/模糊查询：

```php
use LynHuang\LaravelModelUtil\Traits\EncryptsAttributes;

class Member extends Model
{
    use EncryptsAttributes;
    protected $encryptable = ['mobile', 'id_card'];   // 需要加解密的字段列表
}

$member = Member::whereEncrypted('mobile', '13812348888')->first();  // 精确查询
$members = Member::whereEncryptedLike('mobile', '138')->get();       // 模糊查询
```

> 加密基于 Laravel 的 `encrypt()` / `decrypt()`，密钥使用应用的 `APP_KEY`。无法解密的旧数据会保留原值。

## 字段脱敏 MaskHelper

```php
use LynHuang\LaravelModelUtil\Helper\MaskHelper;

MaskHelper::phone('13812348888');          // 138****8888
MaskHelper::email('alice@example.com');    // a***e@example.com
MaskHelper::idCard('330106199001011234');  // 3301**********1234
MaskHelper::bankCard('6222000000001234');  // 6222 **** **** 1234
MaskHelper::mask('123456', 2, 3);          // 12***6
```

## 模型操作审计 RecordsActivity

自动记录创建 / 更新 / 删除日志，需先发布并执行迁移：

```bash
php artisan vendor:publish --tag=model-util-migrations
php artisan migrate
```

```php
use LynHuang\LaravelModelUtil\Traits\RecordsActivity;

class Order extends Model
{
    use RecordsActivity;   // 创建/更新/删除自动写 activity_logs
}

Order::logActivity('手动导入订单', $order);   // 手动记录
```

- 更新时自动记录变更 diff（before / after）到 `properties` 字段。
- 操作人默认取当前登录用户（`auth()->user()`），可通过复写 `activityCauser()` 调整。
- 日志表名通过 `config('model_util.activity_logs_table')` 配置，默认 `activity_logs`。

## 状态机 HasStates

子类声明流转规则，校验非法流转：

```php
use LynHuang\LaravelModelUtil\Traits\HasStates;

class Order extends Model
{
    use HasStates;

    protected function stateTransitions()
    {
        return [
            'pending'   => ['to' => ['paid', 'canceled']],
            'paid'      => ['to' => ['shipped']],
            'shipped'   => ['to' => ['completed']],
            'canceled'  => ['to' => []],
            'completed' => ['to' => []],
        ];
    }
}

$order->canTransitionTo('status', 'paid');   // 是否允许流转（不修改数据）
$order->transitionTo('status', 'paid');      // 校验并切换，非法流转抛 InvalidArgumentException
```

## 唯一编号 OrderNoGenerator

```php
use LynHuang\LaravelModelUtil\Helper\OrderNoGenerator;

OrderNoGenerator::generate('SO');              // SO20260822153045A1B2C33456（前缀+日期+随机+微秒）
OrderNoGenerator::short('U');                  // 不含日期
OrderNoGenerator::generateWithChecksum('NO');  // 末尾追加 Luhn 校验位，防手输错误
```
