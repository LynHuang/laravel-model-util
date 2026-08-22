<?php

namespace LynHuang\LaravelModelUtil\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LynHuang\LaravelModelUtil\Helper\BatchHelper;
use LynHuang\LaravelModelUtil\Tests\TestCase;

class BatchHelperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('modified_at')->nullable();
            $table->timestamps();
        });
    }

    public function testBatchInsertWithInconsistentFields()
    {
        $rows = [
            ['name' => 'Alice', 'email' => 'alice@example.com'],
            ['name' => 'Bob'],
        ];

        $affected = (new BatchHelper())->batchInsert('users', $rows);

        $this->assertEquals(2, $affected);
        $this->assertEquals(2, DB::table('users')->count());
        $this->assertNull(DB::table('users')->where('name', 'Bob')->value('email'));
    }

    public function testBatchUpdate()
    {
        DB::table('users')->insert([
            ['name' => 'Alice', 'email' => 'a@a.com'],
            ['name' => 'Bob', 'email' => 'b@b.com'],
        ]);

        $affected = (new BatchHelper())->batchUpdate('users', [
            ['id' => 1, 'name' => 'A'],
            ['id' => 2, 'name' => 'B'],
        ]);

        $this->assertEquals(2, $affected);
        $this->assertEquals('A', DB::table('users')->where('id', 1)->value('name'));
        $this->assertEquals('B', DB::table('users')->where('id', 2)->value('name'));
    }

    public function testBatchUpdateWritesTimestampByDefault()
    {
        DB::table('users')->insert(['name' => 'Alice', 'email' => 'a@a.com']);

        (new BatchHelper())->batchUpdate('users', [['id' => 1, 'name' => 'A']]);

        $this->assertNotNull(DB::table('users')->where('id', 1)->value('updated_at'));
    }

    public function testBatchUpdateCustomTimestampColumn()
    {
        // 自定义更新时间字段名
        DB::table('users')->insert(['name' => 'Alice', 'email' => 'a@a.com']);

        (new BatchHelper())->batchUpdate('users', [['id' => 1, 'name' => 'A']], 100, 'id', true, 'modified_at');

        $this->assertNotNull(DB::table('users')->where('id', 1)->value('modified_at'));
    }

    public function testBatchUpsert()
    {
        DB::table('users')->insert(['name' => 'Alice', 'email' => 'alice@example.com']);

        $affected = (new BatchHelper())->batchUpsert('users', [
            ['name' => 'Alice2', 'email' => 'alice@example.com'],
            ['name' => 'Bob', 'email' => 'bob@example.com'],
        ], ['email'], ['name']);

        $this->assertEquals(2, $affected);
        $this->assertEquals(2, DB::table('users')->count());
        $this->assertEquals('Alice2', DB::table('users')->where('email', 'alice@example.com')->value('name'));
        $this->assertEquals('Bob', DB::table('users')->where('email', 'bob@example.com')->value('name'));
    }

    public function testBatchDelete()
    {
        DB::table('users')->insert([
            ['name' => 'Alice', 'email' => 'a@a.com'],
            ['name' => 'Bob', 'email' => 'b@b.com'],
        ]);

        $affected = (new BatchHelper())->batchDelete('users', [1, 2]);

        $this->assertEquals(2, $affected);
        $this->assertEquals(0, DB::table('users')->count());
    }

    public function testBatchSoftDeleteAndRestore()
    {
        DB::table('users')->insert(['name' => 'Alice', 'email' => 'a@a.com']);

        $deleted = (new BatchHelper())->batchSoftDelete('users', [1]);
        $this->assertEquals(1, $deleted);
        $this->assertNotNull(DB::table('users')->where('id', 1)->value('deleted_at'));

        $restored = (new BatchHelper())->batchRestore('users', [1]);
        $this->assertEquals(1, $restored);
        $this->assertNull(DB::table('users')->where('id', 1)->value('deleted_at'));
    }

    public function testEmptyArrayReturnsZero()
    {
        $helper = new BatchHelper();

        $this->assertEquals(0, $helper->batchInsert('users', []));
        $this->assertEquals(0, $helper->batchUpdate('users', []));
        $this->assertEquals(0, $helper->batchUpsert('users', [], ['email']));
        $this->assertEquals(0, $helper->batchDelete('users', []));
        $this->assertEquals(0, $helper->batchSoftDelete('users', []));
        $this->assertEquals(0, $helper->batchRestore('users', []));
    }
}
