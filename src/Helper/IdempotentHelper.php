<?php

namespace LynHuang\LaravelModelUtil\Helper;

use Illuminate\Support\Facades\Cache;
use LynHuang\LaravelModelUtil\Exceptions\DuplicateRequestException;

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
     * 幂等执行：首次请求执行业务逻辑，ttl 内重复请求抛出 DuplicateRequestException；
     * 业务抛出异常时自动释放幂等键，允许调用方重试
     *
     * @param string $key 幂等键（建议包含用户 id 与业务 id）
     * @param callable $callback 业务逻辑，返回值透传给调用方
     * @param int $ttl 幂等键有效时长（秒）
     * @return mixed callback 的返回值
     * @throws DuplicateRequestException 重复请求时
     */
    public static function execute(string $key, callable $callback, int $ttl = 60)
    {
        if (self::isDuplicate($key, $ttl)) {
            throw new DuplicateRequestException('请勿重复提交');
        }

        try {
            return call_user_func($callback);
        } catch (\Throwable $e) {
            // 业务执行失败自动释放，允许重试；成功的请求需调用方主动 release
            self::release($key);
            throw $e;
        }
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
