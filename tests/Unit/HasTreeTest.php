<?php

namespace LynHuang\LaravelModelUtil\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use LynHuang\LaravelModelUtil\Tests\TestCase;
use LynHuang\LaravelModelUtil\Traits\HasTree;

class TreeItem extends Model
{
    use HasTree;

    protected $table = 'ht_items';
    protected $guarded = [];
    public $timestamps = false;
}

class HasTreeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ht_items', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->unsignedInteger('parent_id')->nullable();
        });

        // 树结构：1 → (2, 3)；2 → 4
        foreach ([
            ['id' => 1, 'name' => '根', 'parent_id' => null],
            ['id' => 2, 'name' => '子1', 'parent_id' => 1],
            ['id' => 3, 'name' => '子2', 'parent_id' => 1],
            ['id' => 4, 'name' => '孙', 'parent_id' => 2],
        ] as $row) {
            TreeItem::query()->create($row);
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ht_items');

        parent::tearDown();
    }

    public function testRoots()
    {
        $roots = TreeItem::query()->roots()->pluck('id')->all();

        $this->assertEquals([1], $roots);
    }

    public function testChildrenOf()
    {
        $this->assertEquals([2, 3], TreeItem::query()->childrenOf(1)->pluck('id')->all());
        $this->assertEquals([4], TreeItem::query()->childrenOf(2)->pluck('id')->all());
        $this->assertEquals([1], TreeItem::query()->childrenOf(null)->pluck('id')->all());
    }

    public function testDescendantIds()
    {
        $item = TreeItem::query()->find(1);

        $this->assertEquals([2, 4, 3], $item->descendantIds());
        $this->assertEquals([1, 2, 4, 3], $item->descendantIds(null, true));
        $this->assertEquals([4], TreeItem::query()->find(2)->descendantIds());
    }

    public function testScopeDescendantsOf()
    {
        // whereIn 不保证返回顺序，只断言集合一致
        $this->assertEqualsCanonicalizing([2, 4, 3], TreeItem::query()->descendantsOf(1)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([1, 2, 4, 3], TreeItem::query()->descendantsOf(1, true)->pluck('id')->all());
    }

    public function testGetChildren()
    {
        $children = TreeItem::query()->find(1)->getChildren();

        $this->assertEquals([2, 3], $children->pluck('id')->all());
    }

    public function testGetDescendants()
    {
        $descendants = TreeItem::query()->find(1)->getDescendants();

        $this->assertEquals([2, 4, 3], $descendants->pluck('id')->all());
        $this->assertEquals('孙', $descendants->firstWhere('id', 4)->name);
    }

    public function testGetAncestors()
    {
        $ancestors = TreeItem::query()->find(4)->getAncestors();

        // 由近到远：父级 2，祖父级 1
        $this->assertEquals([2, 1], $ancestors->pluck('id')->all());
    }

    public function testLeafNodeHasNoDescendantsAndRootHasNoAncestors()
    {
        // 叶子节点无后代
        $leaf = TreeItem::query()->find(3);
        $this->assertCount(0, $leaf->getDescendants());

        // 根节点无祖先
        $root = TreeItem::query()->find(1);
        $this->assertCount(0, $root->getAncestors());
    }
}
