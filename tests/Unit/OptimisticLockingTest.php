<?php

namespace LynHuang\LaravelModelUtil\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LynHuang\LaravelModelUtil\Exceptions\OptimisticLockException;
use LynHuang\LaravelModelUtil\Tests\TestCase;
use LynHuang\LaravelModelUtil\Traits\OptimisticLocking;

class OptiDoc extends Model
{
    use OptimisticLocking;

    protected $table = 'opti_docs';
    protected $guarded = [];
}

class OptimisticLockingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('opti_docs', function ($table) {
            $table->increments('id');
            $table->string('title')->nullable();
            $table->unsignedInteger('version')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('opti_docs');

        parent::tearDown();
    }

    public function testCreateInitializesVersion()
    {
        $doc = OptiDoc::query()->create(['title' => 'v0']);

        $this->assertEquals(0, $doc->version);
        $this->assertEquals(0, DB::table('opti_docs')->where('id', 1)->value('version'));
    }

    public function testUpdateIncrementsVersionAutomatically()
    {
        $doc = OptiDoc::query()->create(['title' => 'v0']);

        $doc->update(['title' => 'v1']);

        $this->assertEquals(1, $doc->version);
        $this->assertEquals(1, DB::table('opti_docs')->value('version'));
        $this->assertEquals('v1', DB::table('opti_docs')->value('title'));
    }

    public function testConcurrentModificationThrows()
    {
        $a = OptiDoc::query()->create(['title' => 'v0']);
        $b = OptiDoc::query()->findOrFail($a->getKey());

        // A 先保存成功，版本号 +1
        $a->update(['title' => 'from-a']);
        $this->assertEquals(1, $a->version);

        // B 持有的是旧版本，保存时应检测到冲突
        $b->title = 'from-b';

        $this->expectException(OptimisticLockException::class);
        $b->save();
    }

    public function testSaveWithRetryAppliesChangesOnLatestData()
    {
        $a = OptiDoc::query()->create(['title' => 'v0']);
        $b = OptiDoc::query()->findOrFail($a->getKey());

        $a->update(['title' => 'from-a']);

        // B 冲突后重试：以最新数据为基础重新应用本次修改
        $b->title = 'from-b';
        $b->saveWithRetry(2);

        $this->assertEquals('from-b', DB::table('opti_docs')->value('title'));
        $this->assertEquals(2, DB::table('opti_docs')->value('version'));
        $this->assertEquals(2, $b->version);
    }

    public function testSaveWithRetryThrowsWhenAttemptsExhausted()
    {
        $a = OptiDoc::query()->create(['title' => 'v0']);
        $b = OptiDoc::query()->findOrFail($a->getKey());

        $a->update(['title' => 'from-a']);
        $b->title = 'from-b';

        $this->expectException(OptimisticLockException::class);
        $b->saveWithRetry(1);
    }

    public function testLegacyNullVersionRowStillUpdatable()
    {
        // 历史数据：version 为 NULL
        DB::table('opti_docs')->insert(['title' => 'legacy', 'version' => null]);

        $doc = OptiDoc::query()->first();
        $doc->update(['title' => 'migrated']);

        $row = DB::table('opti_docs')->first();
        $this->assertEquals('migrated', $row->title);
        $this->assertEquals(1, $row->version);
    }
}
