<?php

namespace LynHuang\LaravelModelUtil\Helper;

/**
 * 经纬度距离计算与附近查询辅助
 */
class GeoHelper
{
    /**
     * 地球半径（公里）
     */
    const EARTH_RADIUS_KM = 6371.0;

    /**
     * 计算两点间球面距离（Haversine 公式）
     *
     * @param float $lat1
     * @param float $lng1
     * @param float $lat2
     * @param float $lng2
     * @return float 距离（公里）
     */
    public static function distance($lat1, $lng1, $lat2, $lng2): float
    {
        $lat1 = deg2rad($lat1);
        $lng1 = deg2rad($lng1);
        $lat2 = deg2rad($lat2);
        $lng2 = deg2rad($lng2);

        $dLat = $lat2 - $lat1;
        $dLng = $lng2 - $lng1;

        $a = sin($dLat / 2) ** 2
            + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * 计算以指定点为中心、指定半径（公里）覆盖的经纬度矩形范围
     *
     * 用途：附近查询前先用 lat/lng 范围粗筛（可命中索引），再对粗筛结果做精确球面距离过滤。
     *
     * @param float $lat 纬度
     * @param float $lng 经度
     * @param float $radiusKm 半径（公里）
     * @return array [minLat, maxLat, minLng, maxLng]
     */
    public static function boundingBox($lat, $lng, $radiusKm)
    {
        $latDelta = rad2deg($radiusKm / self::EARTH_RADIUS_KM);
        $cosLat   = cos(deg2rad($lat));

        // 极地附近 cos 接近 0，经度范围直接取全球
        if (abs($cosLat) < 1e-6) {
            return [
                max(-90.0, $lat - $latDelta),
                min(90.0, $lat + $latDelta),
                -180.0,
                180.0,
            ];
        }

        $lngDelta = min(180.0, rad2deg($radiusKm / self::EARTH_RADIUS_KM / $cosLat));

        return [
            max(-90.0, $lat - $latDelta),
            min(90.0, $lat + $latDelta),
            $lng - $lngDelta,
            $lng + $lngDelta,
        ];
    }

    /**
     * 附近查询作用域：过滤出指定半径内（公里）的记录，并附带 distance 字段
     *
     * 先按 bounding box 粗筛（lat/lng 上有索引时可大幅减少精确计算的行数），
     * 再对粗筛结果用 Haversine 精确过滤。
     * 注意：跨越 ±180 度经线的查询（如太平洋上选点）粗筛范围不会回卷，需自行拆分查询。
     *
     * @param $query
     * @param float $lat 当前纬度
     * @param float $lng 当前经度
     * @param float $radiusKm 半径（公里）
     * @param string $latColumn 纬度字段
     * @param string $lngColumn 经度字段
     * @return mixed
     */
    public function scopeNear($query, $lat, $lng, $radiusKm, string $latColumn = 'lat', string $lngColumn = 'lng')
    {
        [$minLat, $maxLat, $minLng, $maxLng] = self::boundingBox($lat, $lng, $radiusKm);

        $haversine = self::haversineExpression($latColumn, $lngColumn);

        return $query
            ->selectRaw($haversine . ' AS distance', [self::EARTH_RADIUS_KM, $lat, $lng, $lat])
            ->whereBetween($latColumn, [$minLat, $maxLat])
            ->whereBetween($lngColumn, [$minLng, $maxLng])
            ->whereRaw($haversine . ' <= ?', [self::EARTH_RADIUS_KM, $lat, $lng, $lat, $radiusKm]);
    }

    /**
     * 按与指定点的球面距离排序（升序，由近到远）
     *
     * 可独立使用，也可与 near() 组合（near 已附带 distance 字段时排序表达式仅用于排序，不产生冲突）：
     *   Shop::query()->near($lat, $lng, 5)->orderByDistance($lat, $lng)->get();
     *
     * @param $query
     * @param float $lat 当前纬度
     * @param float $lng 当前经度
     * @param string $latColumn 纬度字段
     * @param string $lngColumn 经度字段
     * @return mixed
     */
    public function scopeOrderByDistance($query, $lat, $lng, string $latColumn = 'lat', string $lngColumn = 'lng')
    {
        return $query->orderByRaw(
            self::haversineExpression($latColumn, $lngColumn) . ' asc',
            [self::EARTH_RADIUS_KM, $lat, $lng, $lat]
        );
    }

    /**
     * Haversine 距离 SQL 表达式（含绑定占位符，纬度列出现两次需要 4 个绑定值）
     */
    private static function haversineExpression($latColumn, $lngColumn)
    {
        return '(? * acos(cos(radians(?)) * cos(radians(' . $latColumn . ')) '
            . '* cos(radians(' . $lngColumn . ') - radians(?)) + sin(radians(?)) * sin(radians(' . $latColumn . '))))';
    }
}
