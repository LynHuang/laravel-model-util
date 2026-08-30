<?php

namespace LynHuang\LaravelModelUtil\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LynHuang\LaravelModelUtil\Tests\TestCase;
use LynHuang\LaravelModelUtil\Traits\CounterCache;

class CounterUser extends Model
{
    use CounterCache;

    protected $table = 'cc_users';
    protected $guarded = [];
    public $timestamps = false;

    protected $countCaches = [
        'comments_count' => CounterComment::class,
        'articles_count' => [CounterArticle::class, 'author_id'],
    ];
}

class CounterComment extends Model
{
    use SoftDeletes;

    protected $table = 'cc_comments';
    protected $guarded = [];
    public $timestamps = false;
}

class CounterArticle extends Model
{
    protected $table = 'cc_articles';
    protected $guarded = [];
    public $timestamps = false;
}

class CounterCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('cc_users', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->unsignedInteger('comments_count')->default(0);
            $table->unsignedInteger('articles_count')->default(0);
        });
        Schema::create('cc_comments', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('counter_user_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('cc_articles', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('author_id')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('cc_users');
        Schema::dropIfExists('cc_comments');
        Schema::dropIfExists('cc_articles');

        parent::tearDown();
    }

    private function makeUser()
    {
        return CounterUser::query()->create(['name' => 'U1']);
    }

    public function testCreateIncrementsCount()
    {
        $user = $this->makeUser();

        CounterComment::query()->create(['counter_user_id' => $user->id]);

        $this->assertEquals(1, $user->fresh()->comments_count);
    }

    public function testSoftDeleteAndRestoreKeepCountCorrect()
    {
        $user = $this->makeUser();
        $comment = CounterComment::query()->create(['counter_user_id' => $user->id]);
        $this->assertEquals(1, $user->fresh()->comments_count);

        // 软删除：计数 -1
        $comment->delete();
        $this->assertEquals(0, $user->fresh()->comments_count);

        // 恢复：计数 +1
        $comment->restore();
        $this->assertEquals(1, $user->fresh()->comments_count);

        // 强制删除：计数 -1
        $comment->forceDelete();
        $this->assertEquals(0, $user->fresh()->comments_count);
    }

    public function testCustomForeignKey()
    {
        $user = $this->makeUser();

        CounterArticle::query()->create(['author_id' => $user->id]);
        $this->assertEquals(1, $user->fresh()->articles_count);

        CounterArticle::query()->find(1)->delete();
        $this->assertEquals(0, $user->fresh()->articles_count);
    }

    public function testChildWithoutParentKeyIsIgnored()
    {
        $user = $this->makeUser();

        CounterComment::query()->create(['counter_user_id' => null]);

        $this->assertEquals(0, $user->fresh()->comments_count);
    }

    public function testSyncCountCacheFixesDrift()
    {
        $user = $this->makeUser();
        CounterComment::query()->create(['counter_user_id' => $user->id]);

        // 模拟数据漂移
        DB::table('cc_users')->update(['comments_count' => 99]);
        $this->assertEquals(99, $user->fresh()->comments_count);

        $user->syncCountCache();

        $this->assertEquals(1, $user->fresh()->comments_count);
        $this->assertEquals(0, $user->fresh()->articles_count);
    }
}
