<?php

namespace LynHuang\LaravelModelUtil\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use LynHuang\LaravelModelUtil\Tests\TestCase;
use LynHuang\LaravelModelUtil\Traits\EncryptsAttributes;

class EncryptUser extends Model
{
    use EncryptsAttributes;

    protected $table      = 'encrypt_users';
    protected $guarded    = [];
    protected $encryptable = ['mobile'];
    public $timestamps    = false;
}

class EncryptUserNoHashColumn extends Model
{
    use EncryptsAttributes;

    protected $table      = 'encrypt_users_plain';
    protected $guarded    = [];
    protected $encryptable = ['mobile'];
    public $timestamps    = false;
}

class EncryptsAttributesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('encrypt_users', function ($table) {
            $table->increments('id');
            $table->text('mobile')->nullable();
            $table->string('mobile_hash', 64)->nullable()->index();
        });
        Schema::create('encrypt_users_plain', function ($table) {
            $table->increments('id');
            $table->text('mobile')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('encrypt_users');
        Schema::dropIfExists('encrypt_users_plain');

        parent::tearDown();
    }

    // 盲索引密钥派生逻辑与 Trait 保持一致，用于断言期望哈希值
    private function expectedHash($plaintext)
    {
        $key = hash_hmac('sha256', 'laravel-model-util.encrypts-attributes', (string)config('app.key'));

        return hash_hmac('sha256', $plaintext, $key);
    }

    public function testCreateStoresCipherTextAndHash()
    {
        EncryptUser::query()->create(['mobile' => '13812348888']);

        $row = DB::table('encrypt_users')->first();
        $this->assertNotEquals('13812348888', $row->mobile);
        $this->assertEquals($this->expectedHash('13812348888'), $row->mobile_hash);
        $this->assertEquals('13812348888', decrypt($row->mobile));
    }

    public function testRetrievedModelReturnsPlaintextAndNotDirty()
    {
        EncryptUser::query()->create(['mobile' => '13812348888']);

        $user = EncryptUser::query()->first();
        $this->assertEquals('13812348888', $user->mobile);
        $this->assertFalse($user->isDirty('mobile'));
    }

    public function testSaveWithoutChangesKeepsCipherUnchanged()
    {
        $user = EncryptUser::query()->create(['mobile' => '13812348888']);
        $firstCipher = DB::table('encrypt_users')->value('mobile');

        // encrypt() 每次产生不同密文，重复加密会导致密文变化
        $user->save();

        $this->assertEquals($firstCipher, DB::table('encrypt_users')->value('mobile'));
    }

    public function testInMemoryPlaintextAfterSave()
    {
        $user = new EncryptUser();
        $user->mobile = '13812348888';
        $user->save();

        $this->assertEquals('13812348888', $user->mobile);
    }

    public function testWhereEncryptedExactMatch()
    {
        EncryptUser::query()->create(['mobile' => '13812348888']);
        EncryptUser::query()->create(['mobile' => '13899990000']);

        $this->assertEquals(1, EncryptUser::whereEncrypted('mobile', '13812348888')->count());
        $this->assertEquals(0, EncryptUser::whereEncrypted('mobile', '13900000000')->count());

        $found = EncryptUser::whereEncrypted('mobile', '13899990000')->first();
        $this->assertEquals('13899990000', $found->mobile);
    }

    public function testUpdateReplacesHash()
    {
        $user = EncryptUser::query()->create(['mobile' => '13812348888']);

        $user->update(['mobile' => '13800001111']);

        $this->assertEquals(0, EncryptUser::whereEncrypted('mobile', '13812348888')->count());
        $this->assertEquals(1, EncryptUser::whereEncrypted('mobile', '13800001111')->count());
    }

    public function testClearValueClearsHash()
    {
        $user = EncryptUser::query()->create(['mobile' => '13812348888']);

        $user->update(['mobile' => null]);

        $row = DB::table('encrypt_users')->first();
        $this->assertNull($row->mobile_hash);
        $this->assertEquals(1, EncryptUser::whereEncrypted('mobile', null)->count());
    }

    public function testWhereEncryptedLikeThrows()
    {
        $this->expectException(LogicException::class);
        EncryptUser::whereEncryptedLike('mobile', '138');
    }

    public function testWhereEncryptedOnUnlistedFieldThrows()
    {
        $this->expectException(LogicException::class);
        EncryptUser::whereEncrypted('name', 'x');
    }

    public function testSaveWithoutHashColumnStillWorks()
    {
        // 未创建盲索引列时不影响加解密存取（兼容升级）
        EncryptUserNoHashColumn::query()->create(['mobile' => '13812348888']);

        $row = DB::table('encrypt_users_plain')->first();
        $this->assertEquals('13812348888', decrypt($row->mobile));

        $user = EncryptUserNoHashColumn::query()->first();
        $this->assertEquals('13812348888', $user->mobile);
    }

    public function testWhereEncryptedWithoutHashColumnThrows()
    {
        EncryptUserNoHashColumn::query()->create(['mobile' => '13812348888']);

        $this->expectException(LogicException::class);
        EncryptUserNoHashColumn::whereEncrypted('mobile', '13812348888')->get();
    }

    public function testBackfillEncryptHashes()
    {
        EncryptUser::query()->create(['mobile' => '13812348888']);
        EncryptUser::query()->create(['mobile' => '13899990000']);
        // 模拟历史数据：盲索引列被清空
        DB::table('encrypt_users')->update(['mobile_hash' => null]);
        $this->assertEquals(0, EncryptUser::whereEncrypted('mobile', '13812348888')->count());

        $updated = EncryptUser::backfillEncryptHashes();

        $this->assertEquals(2, $updated);
        $this->assertEquals(1, EncryptUser::whereEncrypted('mobile', '13812348888')->count());
        $this->assertEquals(1, EncryptUser::whereEncrypted('mobile', '13899990000')->count());
    }
}
