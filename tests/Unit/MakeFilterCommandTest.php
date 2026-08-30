<?php

namespace LynHuang\LaravelModelUtil\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use LynHuang\LaravelModelUtil\Tests\TestCase;

class MakeFilterCommandTest extends TestCase
{
    /**
     * 临时项目根目录（setBasePath 指过去，同时控制生成路径与自定义 stub 的读取位置）
     *
     * @var string
     */
    private $projectPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectPath = sys_get_temp_dir() . '/model-util-make-filter-' . uniqid();
        mkdir($this->projectPath . '/app', 0777, true);
        // GeneratorCommand 通过 basePath 下的 composer.json 推断应用根命名空间
        file_put_contents($this->projectPath . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => 'app']],
        ]));
        $this->app->setBasePath($this->projectPath);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->projectPath);

        parent::tearDown();
    }

    public function testCreatesFilterClassWithStubContent()
    {
        $this->artisan('make:filter', ['name' => 'UserFilter'])->assertExitCode(0);

        $path = $this->projectPath . '/app/Filters/UserFilter.php';
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertStringContainsString('namespace App\\Filters;', $content);
        $this->assertStringContainsString('class UserFilter extends QueryFilter', $content);
        $this->assertStringContainsString('public function cname($value)', $content);
    }

    public function testNestedNamespace()
    {
        $this->artisan('make:filter', ['name' => 'Admin/UserFilter'])->assertExitCode(0);

        $path = $this->projectPath . '/app/Filters/Admin/UserFilter.php';
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertStringContainsString('namespace App\\Filters\\Admin;', $content);
        $this->assertStringContainsString('class UserFilter extends QueryFilter', $content);
    }

    public function testConfigurableDirectory()
    {
        // 生成目录可通过配置修改，命名空间随目录同步变化
        config(['model_util.filters.directory' => 'Admin/Filters']);

        $this->artisan('make:filter', ['name' => 'UserFilter'])->assertExitCode(0);

        $path = $this->projectPath . '/app/Admin/Filters/UserFilter.php';
        $this->assertFileExists($path);
        $this->assertStringContainsString('namespace App\\Admin\\Filters;', file_get_contents($path));
    }

    public function testUsesPublishedStubWhenAvailable()
    {
        // 模拟 vendor:publish 发布后的自定义 stub
        $stubDir = $this->projectPath . '/stubs/model-util';
        mkdir($stubDir, 0777, true);
        file_put_contents($stubDir . '/filter.stub', <<<'STUB'
<?php

namespace {{ namespace }};

use LynHuang\LaravelModelUtil\Filter\QueryFilter;

class {{ class }} extends QueryFilter
{
    // 自定义骨架
}
STUB);

        $this->artisan('make:filter', ['name' => 'UserFilter'])->assertExitCode(0);

        $content = file_get_contents($this->projectPath . '/app/Filters/UserFilter.php');
        $this->assertStringContainsString('自定义骨架', $content);
        $this->assertStringNotContainsString('cname', $content);
    }

    public function testRefusesToOverwriteWithoutForce()
    {
        $this->artisan('make:filter', ['name' => 'UserFilter'])->assertExitCode(0);
        $path = $this->projectPath . '/app/Filters/UserFilter.php';

        // 模拟用户改动过文件
        file_put_contents($path, "<?php\n// 已被修改\n");

        // 不带 --force：不覆盖
        $this->artisan('make:filter', ['name' => 'UserFilter']);
        $this->assertStringContainsString('已被修改', file_get_contents($path));

        // 带 --force：覆盖为 stub 内容
        $this->artisan('make:filter', ['name' => 'UserFilter', '--force' => true]);
        $this->assertStringContainsString('class UserFilter extends QueryFilter', file_get_contents($path));
    }
}
