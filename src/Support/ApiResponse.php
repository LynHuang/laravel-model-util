<?php

namespace LynHuang\LaravelModelUtil\Support;

use Illuminate\Pagination\AbstractPaginator;
use LynHuang\LaravelModelUtil\Exceptions\InvalidParamException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 统一 API 响应格式辅助
 *
 * 统一返回 ['code' => 0, 'message' => 'ok', 'data' => ...] 结构。
 * 控制器中直接 return ApiResponse::success(...) 即可。
 */
class ApiResponse
{
    /**
     * 成功响应
     *
     * @param mixed $data
     * @param string $message
     * @param int $code
     * @return array
     */
    public static function success($data = null, string $message = 'ok', int $code = 0)
    {
        return [
            'code'    => $code,
            'message' => $message,
            'data'    => $data,
        ];
    }

    /**
     * 失败响应
     *
     * @param string $message
     * @param int $code
     * @param mixed $data
     * @return array
     */
    public static function fail(string $message = 'error', int $code = -1, $data = null)
    {
        return [
            'code'    => $code,
            'message' => $message,
            'data'    => $data,
        ];
    }

    /**
     * 分页数据响应
     *
     * @param AbstractPaginator|\Illuminate\Pagination\LengthAwarePaginator $paginator
     * @param string $message
     * @return array
     */
    public static function paginate($paginator, string $message = 'ok')
    {
        return self::success([
            'items' => $paginator->items(),
            'meta'  => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ], $message);
    }

    /**
     * 把异常转换为统一失败响应（供全局异常渲染使用）
     *
     * code 映射规则：
     * - InvalidParamException（包内参数错误）固定为 422；
     * - HttpException（abort(404) 等）取其 HTTP 状态码；
     * - 其余异常使用 $defaultCode。
     *
     * 示例（在异常渲染中注册）：
     *   $exceptions->render(function (InvalidParamException $e, Request $request) {
     *       return response()->json(ApiResponse::fromThrowable($e));
     *   });
     *
     * @param \Throwable $e
     * @param int $defaultCode 兜底 code
     * @return array
     */
    public static function fromThrowable(\Throwable $e, int $defaultCode = -1)
    {
        if ($e instanceof InvalidParamException) {
            return self::fail($e->getMessage(), 422);
        }

        if ($e instanceof HttpException) {
            return self::fail($e->getMessage() ?: 'error', $e->getStatusCode());
        }

        return self::fail($e->getMessage(), $defaultCode);
    }
}
