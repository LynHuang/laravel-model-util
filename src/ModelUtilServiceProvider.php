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
     *
     * 配置 sql_log.slow_ms 大于 0 时进入慢查询模式：只记录耗时超过阈值的语句，
     * 且级别固定为 warning；未配置（默认 0）时记录全部 SQL，级别取 sql_log.level。
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

            $slowMs = (int)($options['slow_ms'] ?? 0);
            if ($slowMs > 0) {
                // 慢查询模式：低于阈值的语句直接跳过
                if ($query->time < $slowMs) {
                    return;
                }
                $level = 'warning';
            } else {
                $level = $options['level'] ?? 'debug';
            }

            $channel = $options['channel'] ?? null;
            $logger  = $channel ? Log::channel($channel) : Log::stack([Log::getDefaultDriver()]);

            $context = [
                'sql'        => $query->sql,
                'bindings'   => $query->bindings,
                'time_ms'    => round($query->time, 2),
                'connection' => $query->connectionName,
            ];
            if ($slowMs > 0) {
                $context['slow_ms'] = $slowMs;
            }

            $logger->{$level}('SQL executed', $context);
        });
    }
}
