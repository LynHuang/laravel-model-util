<?php

namespace LynHuang\LaravelModelUtil;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class ModelUtilServiceProvider extends ServiceProvider
{
    /**
     * 注册服务与配置
     */
    public function register()
    {
        // 合并默认配置，保证未发布配置时 config('model_util.*') 依然可用
        $this->mergeConfigFrom(__DIR__ . '/../config/model_util.php', 'model_util');
    }

    /**
     * 启动服务
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/model_util.php' => config_path('model_util.php'),
            ], 'model-util-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'model-util-migrations');
        }

        $this->registerSqlLog();
    }

    /**
     * 注册 SQL 日志监听
     *
     * 开启条件：config('model_util.sql_log.enabled') 为 true 且非生产环境。
     * 运行时动态判断，方便开发环境随时开关。
     */
    protected function registerSqlLog()
    {
        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $query) {
            $options = config('model_util.sql_log', []);

            // 未开启则不记录
            if (empty($options['enabled'])) {
                return;
            }

            // 生产环境强制关闭，防止敏感 SQL 写入日志
            if (config('app.env') === 'production') {
                return;
            }

            $channel = $options['channel'] ?? null;
            $level   = $options['level'] ?? 'debug';
            $logger  = $channel ? Log::channel($channel) : Log::stack([Log::getDefaultDriver()]);

            $logger->{$level}('SQL executed', [
                'sql'        => $query->sql,
                'bindings'   => $query->bindings,
                'time_ms'    => round($query->time, 2),
                'connection' => $query->connectionName,
            ]);
        });
    }
}
