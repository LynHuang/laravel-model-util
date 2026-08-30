<?php

namespace LynHuang\LaravelModelUtil\Helper;

use Illuminate\Support\Facades\Cache;

/**
 * 唯一编号生成辅助
 *
 * 适用于订单号、流水号等场景。默认格式：前缀 + 年月日时分秒 + 随机串 + 微秒。
 */
class OrderNoGenerator
{
    /**
     * 生成唯一编号（含日期）
     *
     * @param string $prefix 前缀，如 'SO'、'ORDER'
     * @param string $suffix 后缀
     * @return string 如 SO20260822153045A1B2C33456
     */
    public static function generate(string $prefix = '', string $suffix = '')
    {
        $date  = date('YmdHis');
        $rand  = strtoupper(substr(uniqid(), -6));
        $micro = substr((string)(microtime(true) * 10000), -4);

        return $prefix . $date . $rand . $micro . $suffix;
    }

    /**
     * 生成不含日期的短编号（适合有自增主键/雪花ID的拼接场景）
     *
     * @param string $prefix
     * @param string $suffix
     * @return string
     */
    public static function short(string $prefix = '', string $suffix = '')
    {
        $rand  = strtoupper(substr(uniqid(), -6));
        $micro = substr((string)(microtime(true) * 10000), -4);

        return $prefix . $rand . $micro . $suffix;
    }

    /**
     * 生成带校验位的编号（末尾追加 Luhn 校验位，防止手输错误）
     *
     * @param string $prefix
     * @param string $suffix
     * @return string
     */
    public static function generateWithChecksum(string $prefix = '', string $suffix = '')
    {
        $body = self::generate($prefix, $suffix);

        return $body . self::luhnCheckDigit($body);
    }

    /**
     * 校验带校验位编号的正确性（与 generateWithChecksum 配套）
     *
     * @param string $no 编号（末位为校验位）
     * @return bool
     */
    public static function validateChecksum(string $no): bool
    {
        if ($no === '') {
            return false;
        }

        $body   = substr($no, 0, -1);
        $digit  = substr($no, -1);
        $digits = preg_replace('/\D/', '', $body);

        // 主体至少要有一个数字才能参与校验
        if (!ctype_digit($digit) || $digits === '') {
            return false;
        }

        return self::luhnCheckDigit($body) === (int)$digit;
    }

    /**
     * 生成按天递增的序列编号（防并发，格式：前缀 + Ymd + '-' + 补零序列）
     *
     * 例：generateWithSequence('SO') → SO20260830-000123
     * 序列基于缓存原子自增，同一天内保证不重复、可读且可排序。
     * 多进程 / 多机部署需使用共享缓存驱动（redis / memcached 等），
     * 单机 file / array 驱动仅保证单进程内不重复。
     *
     * @param string $prefix 前缀
     * @param int $padLength 序列号补零位数
     * @param int $ttl 序列缓存键的有效时长（秒），需覆盖跨天边界，默认 2 天
     * @return string
     */
    public static function generateWithSequence(string $prefix = '', int $padLength = 6, int $ttl = 172800)
    {
        $date = date('Ymd');
        $key  = 'model_util:order_no_sequence:' . $prefix . ':' . $date;

        Cache::add($key, 0, $ttl);
        $seq = Cache::increment($key);

        return $prefix . $date . '-' . str_pad((string)$seq, $padLength, '0', STR_PAD_LEFT);
    }

    /**
     * 计算 Luhn 校验位
     *
     * @param string $str
     * @return int
     */
    protected static function luhnCheckDigit(string $str)
    {
        $sum = 0;
        $str = preg_replace('/\D/', '', $str);
        $length = strlen($str);

        for ($i = 0; $i < $length; $i++) {
            $digit = (int)$str[$length - 1 - $i];
            if ($i % 2 === 0) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return (10 - ($sum % 10)) % 10;
    }
}
