<?php

namespace LynHuang\LaravelModelUtil\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use LynHuang\LaravelModelUtil\Exceptions\InvalidParamException;
use LynHuang\LaravelModelUtil\Filter\QueryFilter;
use LynHuang\LaravelModelUtil\Tests\TestCase;
use LynHuang\LaravelModelUtil\Traits\UseFilter;

class User extends Model
{
    use UseFilter;

    protected $table = 'users';
}

class UserWithUid extends Model
{
    use UseFilter;

    protected $table = 'users';

    protected $primaryKey = 'uid';
}

class UserFilter extends QueryFilter
{
    protected $sortable = ['id', 'created_at', 'name'];

    public function cname($value)
    {
        $this->analyzeParam('name', 'lk:' . $value);
    }

    public function name($value)
    {
        $this->analyzeParam('name', $value);
    }
}

class UserFilterWithCreatedAtColumn extends QueryFilter
{
    protected $createdAtColumn = 'created_date';
}

class FilterableUserFilter extends QueryFilter
{
    protected $filterable = [
        'name'   => 'lk',
        'price'  => ['ge', 'le'],
        'status' => 'in',
    ];

    public function cname($value)
    {
        $this->analyzeParam('name', 'lk:' . $value);
    }
}

class QueryFilterTest extends TestCase
{
    private function applyFilter(array $inputs)
    {
        $filter = new UserFilter($inputs);
        return $filter->apply(User::query());
    }

    private function applyFilterable(array $inputs)
    {
        $filter = new FilterableUserFilter($inputs);
        return $filter->apply(User::query());
    }

    public function testArrayConstructor()
    {
        $builder = $this->applyFilter(['cname' => '黄']);

        $this->assertCount(1, $builder->getQuery()->wheres);
    }

    public function testSkipInternalMethods()
    {
        // 请求参数与基类内部方法同名时不应被触发，也不应抛出异常
        $builder = $this->applyFilter(['throwError' => 'x', 'cname' => '黄']);

        $wheres = $builder->getQuery()->wheres;
        $this->assertCount(1, $wheres);
        $this->assertEquals('name', $wheres[0]['column']);
    }

    public function testOperatorParsing()
    {
        $builder = $this->applyFilter(['id' => 'gt:5']);

        $wheres = $builder->getQuery()->wheres;
        $this->assertEquals('id', $wheres[0]['column']);
        $this->assertEquals('>', $wheres[0]['operator']);
        $this->assertEquals('5', $wheres[0]['value']);
    }

    public function testNotBetweenAndNotNull()
    {
        $builder = $this->applyFilter(['id' => 'nb:1,5', 'name' => 'nn:1']);

        $wheres = $builder->getQuery()->wheres;
        $this->assertCount(2, $wheres);
        $this->assertEquals('between', $wheres[0]['type']);
        $this->assertEquals('NotNull', $wheres[1]['type']);
    }

    public function testCreatedAtShortcutRange()
    {
        $builder = $this->applyFilter(['created_at' => 'today']);

        $wheres = $builder->getQuery()->wheres;
        $this->assertCount(1, $wheres);
        $this->assertEquals('between', $wheres[0]['type']);
    }

    public function testSortWithinWhitelist()
    {
        $builder = $this->applyFilter(['sort' => '-created_at,name']);

        $orders = $builder->getQuery()->orders;
        $this->assertCount(2, $orders);
        $this->assertEquals('created_at', $orders[0]['column']);
        $this->assertEquals('desc', $orders[0]['direction']);
        $this->assertEquals('name', $orders[1]['column']);
        $this->assertEquals('asc', $orders[1]['direction']);
    }

    public function testSortInvalidFieldThrows()
    {
        $this->expectException(InvalidParamException::class);
        $this->applyFilter(['sort' => 'id;drop table users']);
    }

    public function testPerPage()
    {
        // per_page 在 apply 时生效，与 paginate 用法一致
        $filter = new UserFilter(['per_page' => 20]);
        $filter->apply(User::query());
        $this->assertEquals(20, $filter->getPerPage());

        $filter = new UserFilter(['per_page' => 0]);
        $filter->apply(User::query());
        $this->assertEquals(1, $filter->getPerPage());
    }

    public function testIdUsesModelKeyName()
    {
        // 主键字段随模型变化，而非硬编码 id
        $filter  = new UserFilter(['id' => 'gt:5']);
        $builder = $filter->apply(UserWithUid::query());

        $wheres = $builder->getQuery()->wheres;
        $this->assertEquals('uid', $wheres[0]['column']);
    }

    public function testCreatedAtColumnIsConfigurable()
    {
        // 子类可覆盖 created_at 字段名
        $filter  = new UserFilterWithCreatedAtColumn(['created_at' => 'today']);
        $builder = $filter->apply(User::query());

        $wheres = $builder->getQuery()->wheres;
        $this->assertEquals('created_date', $wheres[0]['column']);
    }

    public function testAndCombine()
    {
        $builder = $this->applyFilter(['id' => 'gt:5&&lt:10']);

        $this->assertCount(2, $builder->getQuery()->wheres);
    }

    public function testOrCombine()
    {
        $builder = $this->applyFilter(['id' => 'eq:1||eq:2']);

        $this->assertCount(1, $builder->getQuery()->wheres);
        $this->assertEquals('Nested', $builder->getQuery()->wheres[0]['type']);
    }

    public function testInvalidBetweenThrows()
    {
        $this->expectException(InvalidParamException::class);
        $this->applyFilter(['id' => 'bt:1,2,3']);
    }

    public function testArrayValueIsIgnored()
    {
        // 数组类型的请求值不应触发类型错误
        $builder = $this->applyFilter(['id' => ['1', '2']]);

        $this->assertEmpty($builder->getQuery()->wheres);
    }

    public function testFilterableSingleOperator()
    {
        $builder = $this->applyFilterable(['name' => '黄']);

        $where = $builder->getQuery()->wheres[0];
        $this->assertEquals('name', $where['column']);
        $this->assertEquals('like', $where['operator']);
        $this->assertEquals('%黄%', $where['value']);
    }

    public function testFilterableMultiOperatorsWithExplicitPrefix()
    {
        $builder = $this->applyFilterable(['price' => 'ge:10&&le:20']);

        $wheres = $builder->getQuery()->wheres;
        $this->assertCount(2, $wheres);
        $this->assertEquals('>=', $wheres[0]['operator']);
        $this->assertEquals('<=', $wheres[1]['operator']);
    }

    public function testFilterablePlainValueUsesFirstOperator()
    {
        // 不带操作符前缀且白名单有多个操作符时，取第一个作为默认
        $builder = $this->applyFilterable(['price' => '10']);

        $this->assertEquals('>=', $builder->getQuery()->wheres[0]['operator']);
        $this->assertEquals('price', $builder->getQuery()->wheres[0]['column']);
    }

    public function testFilterableInOperator()
    {
        $builder = $this->applyFilterable(['status' => '1,2']);

        $where = $builder->getQuery()->wheres[0];
        $this->assertEquals('status', $where['column']);
        $this->assertEquals(['1', '2'], $where['values']);
    }

    public function testFilterableWhitelistRejectsOtherOperators()
    {
        // gt 不在 price 的白名单内，拒绝查询
        $this->expectException(InvalidParamException::class);
        $this->applyFilterable(['price' => 'gt:5']);
    }

    public function testFilterableUnknownParamIgnored()
    {
        $builder = $this->applyFilterable(['foo' => 'x']);

        $this->assertEmpty($builder->getQuery()->wheres);
    }

    public function testFilterableDoesNotShadowUnlistedParams()
    {
        // cname 不在映射内，仍走自定义过滤方法
        $builder = $this->applyFilterable(['cname' => '黄']);

        $this->assertEquals('name', $builder->getQuery()->wheres[0]['column']);
    }
}
