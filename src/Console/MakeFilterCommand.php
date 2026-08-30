<?php

namespace LynHuang\LaravelModelUtil\Console;

use Illuminate\Console\GeneratorCommand;

/**
 * 生成请求过滤器类脚手架
 *
 * php artisan make:filter UserFilter        → app/Filters/UserFilter.php
 * php artisan make:filter Admin/UserFilter  → app/Filters/Admin/UserFilter.php
 */
class MakeFilterCommand extends GeneratorCommand
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'make:filter {name : 过滤器类名，如 UserFilter}
                            {--force : 类已存在时覆盖}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '创建一个请求过滤器类（继承 QueryFilter）';

    /**
     * 生成文件的类型（创建成功 / 已存在的提示文案）
     *
     * @var string
     */
    protected $type = 'Filter';

    /**
     * stub 模板路径
     *
     * 用户已通过 vendor:publish --tag=model-util-stubs 发布自定义 stub 时优先使用，
     * 否则回退到包内默认模板（勿对包内 stubs 目录加 export-ignore）。
     */
    protected function getStub()
    {
        $customPath = $this->laravel->basePath('stubs/model-util/filter.stub');

        return file_exists($customPath) ? $customPath : __DIR__ . '/../../stubs/filter.stub';
    }

    /**
     * 默认命名空间：app 根命名空间 + 生成目录
     *
     * 生成目录通过 config('model_util.filters.directory') 配置（相对 app/ 目录，默认 Filters），
     * 命名空间随目录同步变化：如配置 'Admin/Filters' → App\Admin\Filters\XxxFilter
     *
     * @param string $rootNamespace
     * @return string
     */
    protected function getDefaultNamespace($rootNamespace)
    {
        $directory = trim((string)config('model_util.filters.directory', 'Filters'), '/');

        return $directory !== ''
            ? $rootNamespace . '\\' . str_replace('/', '\\', $directory)
            : $rootNamespace;
    }
}
