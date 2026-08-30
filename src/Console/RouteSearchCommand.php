<?php

namespace LynHuang\LaravelModelUtil\Console;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;

/**
 * 模糊搜索路由：按关键字匹配 URI / 路由名称 / 控制器动作，格式化表格输出
 *
 * php artisan route:search user          # 关键字模糊匹配
 * php artisan route:search order --method=POST
 * php artisan route:search               # 不带关键字列出全部路由
 */
class RouteSearchCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'route:search {keyword? : 匹配 URI / 路由名称 / 控制器动作的关键字}
                            {--method= : 按请求方式过滤，如 GET / POST}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '模糊搜索路由，输出请求方式、URI 与控制器方法';

    /**
     * 执行搜索
     *
     * @param Router $router
     * @return int
     */
    public function handle(Router $router)
    {
        $keyword = Str::lower(trim((string)$this->argument('keyword')));
        $method  = Str::upper(trim((string)$this->option('method')));

        $rows = [];
        foreach ($router->getRoutes() as $route) {
            if ($method !== '' && !in_array($method, $route->methods(), true)) {
                continue;
            }
            if ($keyword !== '' && !$this->matches($route, $keyword)) {
                continue;
            }

            $rows[] = $this->formatRoute($route);
        }

        if (empty($rows)) {
            $this->info('未找到匹配的路由' . ($keyword !== '' ? "（关键字：{$keyword}）" : ''));
            return 0;
        }

        $this->table(['请求方式', 'URI', '控制器@方法'], $rows);
        $this->info('共找到 ' . count($rows) . ' 条路由');

        return 0;
    }

    /**
     * 关键字是否命中路由（URI / 名称 / 控制器动作，大小写不敏感）
     *
     * @param Route $route
     * @param string $keyword 已转小写的关键字
     * @return bool
     */
    private function matches(Route $route, $keyword)
    {
        $targets = [
            Str::lower($route->uri()),
            Str::lower((string)$route->getName()),
            Str::lower($route->getActionName()),
        ];

        foreach ($targets as $target) {
            if (Str::contains($target, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 格式化单条路由
     *
     * @param Route $route
     * @return array [请求方式, URI, 控制器@方法]
     */
    private function formatRoute(Route $route)
    {
        return [
            implode('|', $route->methods()),
            $route->uri(),
            $route->getActionName(),
        ];
    }
}
