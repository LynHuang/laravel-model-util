<?php

namespace LynHuang\LaravelModelUtil\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LynHuang\LaravelModelUtil\Tests\TestCase;
use LynHuang\LaravelModelUtil\Traits\EncryptsAttributes;
use LynHuang\LaravelModelUtil\Traits\RecordsActivity;

class ActivityUser extends Model
{
    use SoftDeletes, RecordsActivity;

    protected $table   = 'activity_users';
    protected $guarded = [];
    public $timestamps = false;
}

// 在默认排除项基础上追加模型自己的排除字段
class ActivitySecretUser extends Model
{
    use RecordsActivity;

    protected $table            = 'activity_users';
    protected $guarded          = [];
    protected $activityExcludes = ['secret'];
    public $timestamps          = false;
}

// 与 EncryptsAttributes 搭配：diff 中的密文应转换为明文
class ActivityEncryptUser extends Model
{
    use RecordsActivity, EncryptsAttributes;

    protected $table      = 'activity_encrypt_users';
    protected $guarded    = [];
    protected $encryptable = ['mobile'];
    public $timestamps    = false;
}

class RecordsActivityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('activity_users', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('password')->nullable();
            $table->string('secret')->nullable();
            $table->softDeletes();
        });
        Schema::create('activity_encrypt_users', function ($table) {
            $table->increments('id');
            $table->text('mobile')->nullable();
            $table->string('mobile_hash', 64)->nullable()->index();
            $table->softDeletes();
        });
        Schema::create('activity_logs', function ($table) {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable()->index();
            $table->text('description')->nullable();
            $table->string('subject_type')->nullable()->index();
            $table->unsignedBigInteger('subject_id')->nullable()->index();
            $table->string('causer_type')->nullable()->index();
            $table->unsignedBigInteger('causer_id')->nullable()->index();
            $table->text('properties')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('activity_users');
        Schema::dropIfExists('activity_encrypt_users');
        Schema::dropIfExists('activity_logs');

        parent::tearDown();
    }

    private function latestLog()
    {
        return DB::table('activity_logs')->orderByDesc('id')->first();
    }

    public function testCreateLogsActivity()
    {
        ActivityUser::query()->create(['name' => '张三']);

        $log = $this->latestLog();
        $this->assertEquals('created', $log->log_name);
        $this->assertEquals(ActivityUser::class, $log->subject_type);
        $this->assertEquals(1, $log->subject_id);
        $this->assertNull($log->causer_type);
    }

    public function testUpdateLogsDiffWithoutSensitiveFields()
    {
        $user = ActivityUser::query()->create(['name' => '张三', 'password' => 'old-secret']);
        DB::table('activity_logs')->delete();

        $user->update(['name' => '李四', 'password' => 'new-secret']);

        $log = $this->latestLog();
        $this->assertEquals('updated', $log->log_name);
        $properties = json_decode($log->properties, true);
        $this->assertEquals('张三', $properties['before']['name']);
        $this->assertEquals('李四', $properties['after']['name']);
        // 密码类敏感字段默认不写入 diff
        $this->assertArrayNotHasKey('password', $properties['before']);
        $this->assertArrayNotHasKey('password', $properties['after']);
    }

    public function testModelCanAppendExcludes()
    {
        $user = ActivitySecretUser::query()->create(['name' => '张三', 'secret' => 'old']);
        DB::table('activity_logs')->delete();

        $user->update(['name' => '李四', 'secret' => 'new']);

        $properties = json_decode($this->latestLog()->properties, true);
        $this->assertArrayNotHasKey('secret', $properties['after']);
        $this->assertArrayHasKey('name', $properties['after']);
    }

    public function testRestoreLogsActivity()
    {
        $user = ActivityUser::query()->create(['name' => '张三']);
        DB::table('activity_logs')->delete();

        $user->delete();
        $user->restore();

        $logNames = DB::table('activity_logs')->pluck('log_name')->all();
        $this->assertContains('deleted', $logNames);
        $this->assertContains('restored', $logNames);
    }

    public function testManualLogActivity()
    {
        $user = ActivityUser::query()->create(['name' => '张三']);
        DB::table('activity_logs')->delete();

        ActivityUser::logActivity('手动导入', $user);

        $log = $this->latestLog();
        $this->assertEquals('manual', $log->log_name);
        $this->assertEquals('手动导入', $log->description);
        $this->assertEquals($user->getKey(), $log->subject_id);
    }

    public function testDiffWithEncryptedFieldRevealsPlaintext()
    {
        $user = ActivityEncryptUser::query()->create(['mobile' => '13812348888']);
        DB::table('activity_logs')->delete();

        $user->update(['mobile' => '13800001111']);

        $properties = json_decode($this->latestLog()->properties, true);
        $this->assertEquals('13812348888', $properties['before']['mobile']);
        $this->assertEquals('13800001111', $properties['after']['mobile']);
        // 库里存的仍是密文
        $this->assertNotEquals('13800001111', DB::table('activity_encrypt_users')->value('mobile'));
    }
}
