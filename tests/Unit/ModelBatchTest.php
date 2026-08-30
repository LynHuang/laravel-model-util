<?php

namespace LynHuang\LaravelModelUtil\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use LynHuang\LaravelModelUtil\Helper\BatchHelper;
use LynHuang\LaravelModelUtil\Tests\TestCase;

class BatchTestUser extends Model
{
    protected $table = 'mb_users';
    protected $guarded = [];
}

class BatchTestPost extends Model
{
    use SoftDeletes;

    protected $table = 'mb_posts';
    protected $guarded = [];
}

class ModelBatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('mb_users', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->timestamps();
        });
        Schema::create('mb_posts', function ($table) {
            $table->increments('id');
            $table->string('title')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('mb_users');
        Schema::dropIfExists('mb_posts');

        parent::tearDown();
    }

    public function testUpdateResolvesTablePrimaryKeyAndTimestamps()
    {
        DB::table('mb_users')->insert(['name' => 'Alice', 'email' => 'a@a.com']);

        $affected = BatchHelper::for(BatchTestUser::class)->update([
            ['id' => 1, 'name' => 'Alice2'],
        ]);

        $this->assertEquals(1, $affected);
        $this->assertEquals('Alice2', DB::table('mb_users')->where('id', 1)->value('name'));
        // 模型开启时间戳时自动维护 updated_at
        $this->assertNotNull(DB::table('mb_users')->where('id', 1)->value('updated_at'));
    }

    public function testUpdateWithoutTimestamps()
    {
        DB::table('mb_users')->insert(['name' => 'Alice', 'email' => 'a@a.com']);

        BatchHelper::for(BatchTestUser::class)->update([['id' => 1, 'name' => 'Alice2']], 100, false);

        $this->assertNull(DB::table('mb_users')->where('id', 1)->value('updated_at'));
    }

    public function testInsertFillsTimestamps()
    {
        BatchHelper::for(BatchTestUser::class)->insert([
            ['name' => 'Alice', 'email' => 'a@a.com'],
            ['name' => 'Bob', 'email' => 'b@b.com'],
        ]);

        $this->assertEquals(2, DB::table('mb_users')->count());
        $row = DB::table('mb_users')->where('name', 'Alice')->first();
        $this->assertNotNull($row->created_at);
        $this->assertNotNull($row->updated_at);
    }

    public function testInsertKeepsProvidedTimestamps()
    {
        BatchHelper::for(BatchTestUser::class)->insert([
            ['name' => 'Alice', 'email' => 'a@a.com', 'created_at' => '2020-01-01 00:00:00'],
        ]);

        $this->assertEquals('2020-01-01 00:00:00', DB::table('mb_users')->where('name', 'Alice')->value('created_at'));
    }

    public function testUpsertFillsTimestamps()
    {
        BatchHelper::for(BatchTestUser::class)->upsert([
            ['name' => 'Alice', 'email' => 'a@a.com'],
        ], ['email'], ['name']);

        $this->assertNotNull(DB::table('mb_users')->where('email', 'a@a.com')->value('created_at'));
    }

    public function testDeleteByPrimaryKeys()
    {
        DB::table('mb_users')->insert([
            ['name' => 'Alice', 'email' => 'a@a.com'],
            ['name' => 'Bob', 'email' => 'b@b.com'],
        ]);

        $affected = BatchHelper::for(BatchTestUser::class)->delete([1, 2]);

        $this->assertEquals(2, $affected);
        $this->assertEquals(0, DB::table('mb_users')->count());
    }

    public function testSoftDeleteAndRestoreOnSoftDeletesModel()
    {
        DB::table('mb_posts')->insert(['title' => 'T1', 'user_id' => 1]);

        $deleted = BatchHelper::for(BatchTestPost::class)->softDelete([1]);
        $this->assertEquals(1, $deleted);
        $this->assertNotNull(DB::table('mb_posts')->where('id', 1)->value('deleted_at'));

        $restored = BatchHelper::for(BatchTestPost::class)->restore([1]);
        $this->assertEquals(1, $restored);
        $this->assertNull(DB::table('mb_posts')->where('id', 1)->value('deleted_at'));
    }

    public function testSoftDeleteOnNonSoftDeletesModelThrows()
    {
        $this->expectException(LogicException::class);
        BatchHelper::for(BatchTestUser::class)->softDelete([1]);
    }

    public function testForWithNonModelClassThrows()
    {
        $this->expectException(LogicException::class);
        BatchHelper::for(\stdClass::class);
    }
}
