<?php

namespace LynHuang\LaravelModelUtil\Tests\Unit;

use Illuminate\Database\Events\QueryExecuted;
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

    /**
     * 派发一条模拟的 QueryExecuted 事件（可指定耗时），触发日志监听
     */
    private function dispatchQueryEvent($timeMs)
    {
        $connection = DB::connection();

        $connection->getEventDispatcher()->dispatch(
            new QueryExecuted('select * from users', [], $timeMs, $connection)
        );
    }

    public function testSqlLogSlowModeOnlyLogsAboveThreshold()
    {
        Log::spy();
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->andReturnNull();

        config(['model_util.sql_log.enabled' => true]);
        config(['model_util.sql_log.slow_ms' => 100]);
        config(['model_util.sql_log.channel' => 'test']);
        config(['app.env' => 'local']);

        // 低于阈值：不记录
        $this->dispatchQueryEvent(10);
        Log::shouldNotHaveReceived('warning');

        // 超过阈值：以 warning 记录，并附带阈值信息
        $this->dispatchQueryEvent(500);
        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context) {
            return $message === 'SQL executed'
                && $context['time_ms'] >= 499
                && $context['slow_ms'] === 100;
        });
    }

    public function testSqlLogNormalModeLogsAllQueriesAtConfiguredLevel()
    {
        Log::spy();
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('debug')->andReturnNull();

        config(['model_util.sql_log.enabled' => true]);
        config(['model_util.sql_log.slow_ms' => 0]);
        config(['model_util.sql_log.channel' => 'test']);
        config(['app.env' => 'local']);

        // slow_ms 未启用时低于阈值的语句也照常记录（原行为）
        $this->dispatchQueryEvent(10);
        Log::shouldHaveReceived('debug');
    }
}
