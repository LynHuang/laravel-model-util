<?php

namespace LynHuang\LaravelModelUtil\Helper;

use Illuminate\Support\Facades\Cache;

/**
 * 防重复提交 / 幂等辅助
 *
 * 基于原子缓存操作：首次写入成功，ttl 内重复写入失败。
 * 适用于表单提交、支付回调等需要幂等的场景。
 */
class IdempotentHelper
{
    /**
     * 标记并判断是否重复请求
     *
     * 用法：$key = "submit:{$userId}:{$orderId}"
     *
     * @param string $key 幂等键（建议包含用户 id 与业务 id）
     * @param int $ttl 有效时长（秒）
     * @return bool true 表示重复请求，false 表示首次请求
     */
    public static function isDuplicate(string $key, int $ttl = 60): bool
    {
        return !Cache::add(self::key($key), true, $ttl);
    }

    /**
     * 手动释放幂等键（业务完成后可主动释放，允许再次提交）
     *
     * @param string $key
     */
    public static function release(string $key)
    {
        Cache::forget(self::key($key));
    }

    /**
     * 内部缓存键（附带场景前缀，避免与其他缓存冲突）
     */
    private static function key(string $key): string
    {
        return 'idempotent:' . $key;
    }
}
