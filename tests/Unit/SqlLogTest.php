<?php

namespace LynHuang\LaravelModelUtil\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LynHuang\LaravelModelUtil\Tests\TestCase;

class SqlLogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
        });
    }

    public function testSqlLogRecordsQueryWhenEnabled()
    {
        Log::spy();
        // spy 对未 stub 的方法默认返回 null，需显式返回 mock 自身
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('debug')->andReturnNull();

        config(['model_util.sql_log.enabled' => true]);
        config(['model_util.sql_log.channel' => 'test']);
        config(['app.env' => 'local']);

        DB::table('users')->insert(['name' => 'Alice']);

        Log::shouldHaveReceived('debug')->withArgs(function ($message, $context) {
            return $message === 'SQL executed' && isset($context['sql']) && isset($context['time_ms']);
        });
    }

    public function testSqlLogDisabledByDefault()
    {
        Log::spy();
        config(['model_util.sql_log.enabled' => false]);
        config(['app.env' => 'local']);

        DB::table('users')->insert(['name' => 'Bob']);

        Log::shouldNotHaveReceived('debug');
    }

    public function testSqlLogDisabledInProduction()
    {
        Log::spy();
        config(['model_util.sql_log.enabled' => true]);
        config(['app.env' => 'production']);

        DB::table('users')->insert(['name' => 'Cathy']);

        Log::shouldNotHaveReceived('debug');
    }
}
