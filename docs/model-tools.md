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

基于 `weight` 字段（可覆盖）提供拖拽排序，支持按分组（如同分类内）排序：

```php
use LynHuang\LaravelModelUtil\Traits\Sortable;

class Menu extends Model
{
    use Sortable;
    protected $sortColumn = 'weight';                  // 排序字段，可覆盖
    protected $sortGroupColumns = ['parent_id'];       // 分组字段，声明后移动/置顶/置底限定同组内
}

$menu->moveUp();              // 上移（有分组时在同组内移动）
$menu->moveDown();            // 下移
$menu->moveToTop();           // 置顶（组内）
$menu->moveToBottom();        // 置底（组内）
Menu::query()->reorder([3, 1, 2]);  // 按主键顺序整体重排（单条 CASE WHEN 批量更新）

Menu::query()->ordered()->get();     // 按排序字段升序查询（scope）
```

> 使用分组字段时，`reorder()` 传入的 id 应为同一分组内的排序结果。

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

模型事件自动加密存储、解密读取，并通过**盲索引列**支持精确查询：

```php
use LynHuang\LaravelModelUtil\Traits\EncryptsAttributes;

class Member extends Model
{
    use EncryptsAttributes;
    protected $encryptable = ['mobile', 'id_card'];   // 需要加解密的字段列表
}

$member = Member::whereEncrypted('mobile', '13812348888')->first();  // 精确查询（走盲索引列）
```

**迁移**：精确查询基于盲索引列（HMAC-SHA256，密钥由 `APP_KEY` 派生），需为每个加密字段增加对应的 `<字段>_hash` 列并建议加索引：

```php
$table->string('mobile_hash', 64)->nullable()->index();
$table->string('id_card_hash', 64)->nullable()->index();
```

已有数据回填：

```php
Member::backfillEncryptHashes();   // 解密存量密文，补写盲索引列
```

> - 加密基于 Laravel 的 `encrypt()` / `decrypt()`，密钥使用应用的 `APP_KEY`。无法解密的旧数据会保留原值。
> - **加密字段无法做模糊查询**：每次加密产生的密文都不同（随机 IV），`whereEncryptedLike` 已废弃，调用会抛出异常。如需模糊检索，请额外维护一个明文或脱敏（`MaskHelper`）的可搜索字段。
> - 未创建盲索引列时不影响加解密存取，只是 `whereEncrypted` 不可用；盲索引列名后缀可通过模型的 `$encryptHashSuffix` 属性覆盖。
> - 通过 Query Builder 的批量 update（`Model::query()->update()` 等）不触发模型事件，不会自动加解密，请走 Eloquent 的 save / update 路径。

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
    use RecordsActivity;   // 创建/更新/删除/软删恢复自动写 activity_logs
}

Order::logActivity('手动导入订单', $order);   // 手动记录
```

- 支持 created / updated / deleted / restored（软删恢复，需模型使用 SoftDeletes）四类事件。
- 更新时自动记录变更 diff（before / after）到 `properties` 字段；`password` 等敏感字段默认排除（见 `config('model_util.activity_excludes')`），模型可通过 `$activityExcludes` 属性追加自己的排除项。
- 操作人默认取当前登录用户（`auth()->user()`），可通过复写 `activityCauser()` 调整。
- 日志表名通过 `config('model_util.activity_logs_table')` 配置，默认 `activity_logs`。

## 状态机 HasStates

子类声明流转规则，校验非法流转；可为目标状态配置 before 守卫 / after 副作用钩子与展示名：

```php
use LynHuang\LaravelModelUtil\Traits\HasStates;

class Order extends Model
{
    use HasStates;

    protected function stateTransitions()
    {
        return [
            'pending'   => ['to' => ['paid', 'canceled']],
            'paid'      => [
                'to'     => ['shipped'],
                'before' => function ($model, $from) {   // 进入 paid 前的守卫
                    return $model->amount > 0;           // 返回 false 时阻止流转并抛异常
                },
                'after'  => function ($model, $from) {   // 进入 paid 后的副作用
                    // 如：Notify::paid($model);
                },
            ],
            'shipped'   => ['to' => ['completed']],
            'canceled'  => ['to' => []],
            'completed' => ['to' => []],
        ];
    }

    protected function stateLabels()   // 可选：状态展示名
    {
        return ['pending' => '待支付', 'paid' => '已支付', 'shipped' => '已发货'];
    }
}

$order->canTransitionTo('status', 'paid');   // 是否允许流转（不修改数据、不触发钩子）
$order->transitionTo('status', 'paid');      // 校验并切换（含钩子），非法流转抛 InvalidArgumentException
$order->stateLabel('status');                // 状态展示名，未配置时原样返回状态值
```

> 钩子在流转时（内存中）触发，与保存时机无关；回调签名统一为 `function ($model, $fromState)`。

## 唯一编号 OrderNoGenerator

```php
use LynHuang\LaravelModelUtil\Helper\OrderNoGenerator;

OrderNoGenerator::generate('SO');              // SO20260822153045A1B2C33456（前缀+日期+随机+微秒）
OrderNoGenerator::short('U');                  // 不含日期
OrderNoGenerator::generateWithChecksum('NO');  // 末尾追加 Luhn 校验位，防手输错误
OrderNoGenerator::validateChecksum($no);       // 校验带校验位的编号是否有效
OrderNoGenerator::generateWithSequence('SO');  // 按天序列发号：SO20260830-000123（防并发，缓存原子自增）
```

> `generateWithSequence` 多进程 / 多机部署需使用共享缓存驱动（redis / memcached 等），序列按天重置、可读可排序。
