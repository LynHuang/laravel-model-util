<?php

namespace LynHuang\LaravelModelUtil\Exceptions;

use InvalidArgumentException;

/**
 * 查询参数格式错误异常
 *
 * 由 QueryFilter 抛出，与 HTTP 响应解耦。
 * 在 HTTP 场景下可在全局异常处理中统一转成 JSON 响应；
 * 在 CLI / 队列场景下可直接捕获处理。
 */
class InvalidParamException extends InvalidArgumentException
{
}
