<?php

namespace LynHuang\LaravelModelUtil\Helper;

/**
 * 敏感数据脱敏辅助
 */
class MaskHelper
{
    /**
     * 手机号脱敏：138****8888
     */
    public static function phone($value)
    {
        if ($value === null || $value === '') {
            return $value;
        }
        return substr($value, 0, 3) . '****' . substr($value, 7);
    }

    /**
     * 邮箱脱敏：a***a@example.com
     */
    public static function email($value)
    {
        if ($value === null || $value === '') {
            return $value;
        }
        $pos = strpos($value, '@');
        if ($pos === false) {
            return $value;
        }
        $name   = substr($value, 0, $pos);
        $domain = substr($value, $pos);
        $masked = mb_substr($name, 0, 1) . str_repeat('*', max(0, mb_strlen($name) - 2)) . mb_substr($name, -1);

        return $masked . $domain;
    }

    /**
     * 身份证号脱敏：3301**********1234
     */
    public static function idCard($value)
    {
        if ($value === null || $value === '') {
            return $value;
        }
        return substr($value, 0, 4) . str_repeat('*', max(0, strlen($value) - 8)) . substr($value, -4);
    }

    /**
     * 银行卡号脱敏：6222 **** **** 1234
     */
    public static function bankCard($value)
    {
        if ($value === null || $value === '') {
            return $value;
        }
        return substr($value, 0, 4) . ' **** **** ' . substr($value, -4);
    }

    /**
     * 通用脱敏：将指定区间替换为掩码字符
     *
     * @param string|null $value 原始值
     * @param int $start 起始位置
     * @param int $length 掩码长度，<=0 表示掩到结尾
     * @param string $char 掩码字符
     * @return string|null
     */
    public static function mask($value, int $start = 0, int $length = 0, string $char = '*')
    {
        if ($value === null || $value === '') {
            return $value;
        }
        $len = strlen($value);
        if ($length <= 0) {
            $length = $len - $start;
        }
        $length = min($length, max(0, $len - $start));

        return substr_replace($value, str_repeat($char, $length), $start, $length);
    }
}
