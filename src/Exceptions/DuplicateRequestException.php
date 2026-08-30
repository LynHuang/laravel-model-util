<?php

namespace LynHuang\LaravelModelUtil\Exceptions;

use RuntimeException;

/**
 * 重复请求异常
 *
 * 由 IdempotentHelper::execute() 在幂等键命中时抛出，与 HTTP 响应解耦，
 * 调用方可捕获后自行转成统一响应。
 */
class DuplicateRequestException extends RuntimeException
{
}
