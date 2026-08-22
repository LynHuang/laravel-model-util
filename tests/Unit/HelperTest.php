<?php

namespace LynHuang\LaravelModelUtil\Tests\Unit;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use LynHuang\LaravelModelUtil\Helper\GeoHelper;
use LynHuang\LaravelModelUtil\Helper\IdempotentHelper;
use LynHuang\LaravelModelUtil\Helper\MaskHelper;
use LynHuang\LaravelModelUtil\Helper\OrderNoGenerator;
use LynHuang\LaravelModelUtil\Helper\TreeHelper;
use LynHuang\LaravelModelUtil\Support\ApiResponse;
use LynHuang\LaravelModelUtil\Tests\TestCase;

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
