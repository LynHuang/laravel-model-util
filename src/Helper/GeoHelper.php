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
     * 附近查询作用域：过滤出指定半径内（公里）的记录，并附带 distance 字段
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
        $haversine = '(? * acos(cos(radians(?)) * cos(radians(' . $latColumn . ')) '
            . '* cos(radians(' . $lngColumn . ') - radians(?)) + sin(radians(?)) * sin(radians(' . $latColumn . '))))';

        return $query
            ->selectRaw($haversine . ' AS distance', [self::EARTH_RADIUS_KM, $lat, $lng, $lat])
            ->whereRaw($haversine . ' <= ?', [self::EARTH_RADIUS_KM, $lat, $lng, $lat, $radiusKm]);
    }
}
