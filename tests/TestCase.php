<?php

namespace LynHuang\LaravelModelUtil\Tests;

use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            \LynHuang\LaravelModelUtil\ModelUtilServiceProvider::class,
        ];
    }

    /**
     * 配置测试数据库为 SQLite 内存库
     */
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }
}
