<?php

namespace LynHuang\LaravelModelUtil\Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use LynHuang\LaravelModelUtil\Tests\TestCase;

class SearchUserController
{
    public function index()
    {
    }

    public function show()
    {
    }
}

class RouteSearchCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['router']->get('/api/users', SearchUserController::class . '@index')->name('users.index');
        $this->app['router']->get('/api/users/{id}', SearchUserController::class . '@show');
        $this->app['router']->post('/api/orders', function () {
            return 'store';
        })->name('orders.store');
    }

    /**
     * 执行命令并返回输出内容
     */
    private function runSearch(array $parameters = [])
    {
        Artisan::call('route:search', $parameters);

        return Artisan::output();
    }

    public function testFuzzyMatchesUriNameAndAction()
    {
        // 关键字命中 URI
        $output = $this->runSearch(['keyword' => 'users']);
        $this->assertStringContainsString('api/users', $output);
        $this->assertStringContainsString(SearchUserController::class . '@index', $output);
        $this->assertStringContainsString('users.index', $output);
        $this->assertStringNotContainsString('api/orders', $output);

        // 关键字命中控制器方法名
        $output = $this->runSearch(['keyword' => 'show']);
        $this->assertStringContainsString('api/users', $output);
        $this->assertStringNotContainsString('orders.store', $output);

        // 关键字命中路由名称（大小写不敏感）
        $output = $this->runSearch(['keyword' => 'ORDERS.STORE']);
        $this->assertStringContainsString('api/orders', $output);
        $this->assertStringContainsString('Closure', $output);
    }

    public function testMethodFilter()
    {
        $output = $this->runSearch(['--method' => 'POST']);
        $this->assertStringContainsString('api/orders', $output);
        $this->assertStringNotContainsString('api/users', $output);

        $output = $this->runSearch(['keyword' => 'user', '--method' => 'POST']);
        $this->assertStringContainsString('未找到匹配的路由', $output);
    }

    public function testListsAllRoutesWithoutKeyword()
    {
        $output = $this->runSearch();

        $this->assertStringContainsString('api/users', $output);
        $this->assertStringContainsString('api/orders', $output);
        $this->assertStringContainsString('共找到 3 条路由', $output);
    }

    public function testNoMatchMessage()
    {
        $output = $this->runSearch(['keyword' => 'not-exists-keyword']);

        $this->assertStringContainsString('未找到匹配的路由', $output);
    }
}
