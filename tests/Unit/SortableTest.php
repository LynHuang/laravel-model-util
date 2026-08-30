<?php

namespace LynHuang\LaravelModelUtil\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use LynHuang\LaravelModelUtil\Tests\TestCase;
use LynHuang\LaravelModelUtil\Traits\Sortable;

class SortItem extends Model
{
    use Sortable;

    protected $table   = 'sort_items';
    protected $guarded = [];
    public $timestamps = false;
}

class SortGroupItem extends Model
{
    use Sortable;

    protected $table           = 'sort_items';
    protected $guarded         = [];
    public $timestamps         = false;
    protected $sortGroupColumns = ['group_id'];
}

class SortableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('sort_items', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('weight')->default(0);
            $table->integer('group_id')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('sort_items');

        parent::tearDown();
    }

    private function seedItems(array $rows)
    {
        foreach ($rows as $row) {
            SortItem::query()->create($row);
        }
    }

    public function testMoveUpAndDownSwapsWithNeighbor()
    {
        $this->seedItems([
            ['name' => 'A', 'weight' => 1],
            ['name' => 'B', 'weight' => 2],
            ['name' => 'C', 'weight' => 3],
        ]);

        $b = SortItem::query()->where('name', 'B')->first();
        $b->moveUp();

        $this->assertEquals(1, SortItem::query()->where('name', 'B')->value('weight'));
        $this->assertEquals(2, SortItem::query()->where('name', 'A')->value('weight'));

        $b = SortItem::query()->where('name', 'B')->first();
        $b->moveDown();

        // 再下移一位即换回原来的位置（B 与 A 交换回原 weight）
        $this->assertEquals(2, SortItem::query()->where('name', 'B')->value('weight'));
        $this->assertEquals(1, SortItem::query()->where('name', 'A')->value('weight'));
    }

    public function testMoveToTopAndBottom()
    {
        $this->seedItems([
            ['name' => 'A', 'weight' => 1],
            ['name' => 'B', 'weight' => 2],
        ]);

        $b = SortItem::query()->where('name', 'B')->first();
        $b->moveToTop();
        $this->assertEquals(0, $b->weight);
        $this->assertEquals(0, SortItem::query()->where('name', 'B')->value('weight'));

        $a = SortItem::query()->where('name', 'A')->first();
        $a->moveToBottom();
        // 此时最大 weight 为 A 自己的 1，置底后 = max + 1 = 2
        $this->assertEquals(2, SortItem::query()->where('name', 'A')->value('weight'));
    }

    public function testReorderBatchUpdatesWeights()
    {
        $this->seedItems([
            ['name' => 'A', 'weight' => 1],
            ['name' => 'B', 'weight' => 2],
            ['name' => 'C', 'weight' => 3],
        ]);

        $model = new SortItem();
        $model->reorder([3, 1, 2]);   // 新顺序：C, A, B

        // 起始值为最小 weight，按新顺序依次 +1
        $this->assertEquals(2, SortItem::query()->where('name', 'C')->value('weight'));
        $this->assertEquals(3, SortItem::query()->where('name', 'A')->value('weight'));
        $this->assertEquals(4, SortItem::query()->where('name', 'B')->value('weight'));
    }

    public function testGroupScopedMoving()
    {
        $this->seedItems([
            ['name' => 'A1', 'weight' => 1, 'group_id' => 1],
            ['name' => 'A2', 'weight' => 2, 'group_id' => 1],
            ['name' => 'B1', 'weight' => 3, 'group_id' => 2],
            ['name' => 'B2', 'weight' => 4, 'group_id' => 2],
        ]);

        // 组 2 内 B2 上移，不应影响组 1
        $b2 = SortGroupItem::query()->where('name', 'B2')->first();
        $b2->moveUp();

        $this->assertEquals(3, SortItem::query()->where('name', 'B2')->value('weight'));
        $this->assertEquals(4, SortItem::query()->where('name', 'B1')->value('weight'));
        // 组 1 不受影响
        $this->assertEquals(1, SortItem::query()->where('name', 'A1')->value('weight'));
        $this->assertEquals(2, SortItem::query()->where('name', 'A2')->value('weight'));

        // 组内置顶：B2 到组 2 最小值之上
        $b2 = SortGroupItem::query()->where('name', 'B2')->first();
        $b2->moveToTop();
        $this->assertEquals(2, SortItem::query()->where('name', 'B2')->value('weight'));
    }

    public function testOrderedScope()
    {
        $this->seedItems([
            ['name' => 'B', 'weight' => 2],
            ['name' => 'A', 'weight' => 1],
        ]);

        $names = SortItem::query()->ordered()->pluck('name')->all();
        $this->assertEquals(['A', 'B'], $names);
    }
}
