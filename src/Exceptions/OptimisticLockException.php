<?php

namespace LynHuang\LaravelModelUtil\Exceptions;

use RuntimeException;

/**
 * 乐观锁冲突异常
 *
 * 由 OptimisticLocking Trait 在保存时检测到版本号不一致（数据已被其他请求修改）抛出。
 */
class OptimisticLockException extends RuntimeException
{
}
