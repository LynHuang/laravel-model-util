<?php

namespace LynHuang\LaravelModelUtil\Tests\Unit;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use LynHuang\LaravelModelUtil\Exceptions\DuplicateRequestException;
use LynHuang\LaravelModelUtil\Exceptions\InvalidParamException;
use LynHuang\LaravelModelUtil\Helper\GeoHelper;
use LynHuang\LaravelModelUtil\Helper\IdempotentHelper;
use LynHuang\LaravelModelUtil\Helper\MaskHelper;
use LynHuang\LaravelModelUtil\Helper\OrderNoGenerator;
use LynHuang\LaravelModelUtil\Helper\TreeHelper;
use LynHuang\LaravelModelUtil\Support\ApiResponse;
use LynHuang\LaravelModelUtil\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class HelperTest extends TestCase
{
    public function testTreeHelperBuildsTree()
    {
        $items = [
            ['id' => 1, 'parent_id' => null, 'name' => '根'],
            ['id' => 2, 'parent_id' => 1, 'name' => '子1'],
            ['id' => 3, 'parent_id' => 1, 'name' => '子2'],
            ['id' => 4, 'parent_id' => 2, 'name' => '孙'],
        ];

        $tree = TreeHelper::toTree($items);

        $this->assertCount(1, $tree);
        $this->assertCount(2, $tree[0]['children']);
        $this->assertEquals('孙', $tree[0]['children'][0]['children'][0]['name']);
    }

    public function testTreeHelperFlattenAndDescendants()
    {
        $items = [
            ['id' => 1, 'parent_id' => null, 'name' => '根'],
            ['id' => 2, 'parent_id' => 1, 'name' => '子1'],
            ['id' => 3, 'parent_id' => 1, 'name' => '子2'],
            ['id' => 4, 'parent_id' => 2, 'name' => '孙'],
        ];

        $tree = TreeHelper::toTree($items);
        $this->assertCount(4, TreeHelper::flatten($tree));
        $this->assertEquals([2, 4, 3], TreeHelper::descendants($items, 1));
        $this->assertEquals([2, 1], TreeHelper::ancestors($items, 4));
    }

    public function testMaskHelper()
    {
        $this->assertEquals('138****8888', MaskHelper::phone('13812348888'));
        $this->assertEquals('a***e@example.com', MaskHelper::email('alice@example.com'));
        $this->assertEquals('3301**********1234', MaskHelper::idCard('330106199001011234'));
        $this->assertEquals('12**', MaskHelper::mask('1234', 2));
        $this->assertNull(MaskHelper::phone(null));
    }

    public function testOrderNoGenerator()
    {
        $no1 = OrderNoGenerator::generate('SO');
        $no2 = OrderNoGenerator::generate('SO');

        $this->assertNotEquals($no1, $no2);
        $this->assertStringStartsWith('SO', $no1);
        $this->assertSame(26, strlen($no1));
        // 带校验位版本：长度比普通版本多 1 位数字，且末尾为数字
        $checksum = OrderNoGenerator::generateWithChecksum('');
        $this->assertSame(strlen(OrderNoGenerator::generate('')) + 1, strlen($checksum));
        $this->assertTrue((bool)preg_match('/[0-9]$/', substr($checksum, -1)));
    }

    public function testApiResponse()
    {
        $this->assertSame([
            'code' => 0, 'message' => 'ok', 'data' => ['a' => 1],
        ], ApiResponse::success(['a' => 1]));

        $this->assertSame([
            'code' => -1, 'message' => 'error', 'data' => null,
        ], ApiResponse::fail());

        $paginator = new LengthAwarePaginator(['a', 'b'], 100, 2, 1);
        $resp = ApiResponse::paginate($paginator);
        $this->assertEquals('b', $resp['data']['items'][1]);
        $this->assertEquals(100, $resp['data']['meta']['total']);
    }

    public function testGeoBoundingBox()
    {
        // 上海附近 50 公里：纬度方向约 0.45 度，经度方向约 0.53 度
        [$minLat, $maxLat, $minLng, $maxLng] = GeoHelper::boundingBox(31.23, 121.47, 50);

        $this->assertEqualsWithDelta(0.45, 31.23 - $minLat, 0.01);
        $this->assertEqualsWithDelta(0.45, $maxLat - 31.23, 0.01);
        $this->assertEqualsWithDelta(0.53, 121.47 - $minLng, 0.01);
        $this->assertEqualsWithDelta(0.53, $maxLng - 121.47, 0.01);

        // 纬度不会被裁剪到 -90~90 之外
        [$minLat2, $maxLat2, , ] = GeoHelper::boundingBox(89.99, 0, 1000);
        $this->assertEquals(90.0, $maxLat2);
        $this->assertGreaterThanOrEqual(-90.0, $minLat2);
    }

    public function testOrderNoValidateChecksum()
    {
        $no = OrderNoGenerator::generateWithChecksum('SO');

        $this->assertTrue(OrderNoGenerator::validateChecksum($no));

        // 改动校验位后校验失败
        $last     = (int)substr($no, -1);
        $tampered = substr($no, 0, -1) . (($last + 1) % 10);
        $this->assertFalse(OrderNoGenerator::validateChecksum($tampered));

        // 空串 / 主体无数字 / 非数字校验位
        $this->assertFalse(OrderNoGenerator::validateChecksum(''));
        $this->assertFalse(OrderNoGenerator::validateChecksum('SOX'));
        $this->assertFalse(OrderNoGenerator::validateChecksum('SO2026X'));
    }

    public function testOrderNoSequence()
    {
        config(['cache.default' => 'array']);
        Cache::flush();

        $no1 = OrderNoGenerator::generateWithSequence('SO');
        $no2 = OrderNoGenerator::generateWithSequence('SO');
        $no3 = OrderNoGenerator::generateWithSequence('SO', 4);

        $this->assertStringStartsWith('SO' . date('Ymd') . '-000001', $no1);
        $this->assertStringEndsWith('-000002', $no2);
        $this->assertStringEndsWith('-0003', $no3);
        $this->assertCount(3, array_unique([$no1, $no2, $no3]));
    }

    public function testIdempotentExecute()
    {
        config(['cache.default' => 'array']);
        Cache::flush();

        $calls  = 0;
        $result = IdempotentHelper::execute('pay:1', function () use (&$calls) {
            $calls++;
            return 'ok';
        }, 60);

        $this->assertSame('ok', $result);
        $this->assertSame(1, $calls);

        // ttl 内重复请求抛异常，业务不再执行
        try {
            IdempotentHelper::execute('pay:1', function () use (&$calls) {
                $calls++;
            }, 60);
            $this->fail('未抛出重复请求异常');
        } catch (DuplicateRequestException $e) {
            $this->assertSame(1, $calls);
        }

        // 业务异常自动释放幂等键，允许重试
        try {
            IdempotentHelper::execute('pay:2', function () {
                throw new \RuntimeException('boom');
            }, 60);
            $this->fail('业务异常未透传');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $retried = IdempotentHelper::execute('pay:2', function () {
            return 'retried';
        }, 60);
        $this->assertSame('retried', $retried);
    }

    public function testApiResponseFromThrowable()
    {
        // 包内参数错误固定 422
        $resp = ApiResponse::fromThrowable(new InvalidParamException('status参数格式错误'));
        $this->assertSame(422, $resp['code']);
        $this->assertSame('status参数格式错误', $resp['message']);

        // HttpException 取 HTTP 状态码
        $resp = ApiResponse::fromThrowable(new NotFoundHttpException('页面不存在'));
        $this->assertSame(404, $resp['code']);

        // 其余异常使用兜底 code
        $resp = ApiResponse::fromThrowable(new \RuntimeException('boom'));
        $this->assertSame(-1, $resp['code']);
        $this->assertSame('boom', $resp['message']);
    }

    public function testGeoDistance()
    {
        $this->assertEquals(0.0, GeoHelper::distance(31.23, 121.47, 31.23, 121.47));
        // 上海-北京约 1067 公里，允许 ±10 公里误差
        $km = GeoHelper::distance(31.2304, 121.4737, 39.9042, 116.4074);
        $this->assertGreaterThan(1057, $km);
        $this->assertLessThan(1077, $km);
    }

    public function testIdempotentHelper()
    {
        config(['cache.default' => 'array']);
        Cache::flush();

        $this->assertFalse(IdempotentHelper::isDuplicate('submit:1:order', 60));
        $this->assertTrue(IdempotentHelper::isDuplicate('submit:1:order', 60));
        IdempotentHelper::release('submit:1:order');
        $this->assertFalse(IdempotentHelper::isDuplicate('submit:1:order', 60));
    }
}
