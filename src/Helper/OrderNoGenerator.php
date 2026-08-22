<?php

namespace LynHuang\LaravelModelUtil\Helper;

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
