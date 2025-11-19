<?php

namespace App\Lib;

use JsonSerializable;

/**
 * 通用的程式执行结果(有些时候function需要回传比较多数据时用来包资料) && Web API标准回传格式.
 */
class CommonResult implements JsonSerializable
{

    /**
     * 结果代码
     *
     * @var int
     */
    public int $code = 0;

    /**
     * 讯息.
     *
     * @var string
     */
    public string $msg = '';

    /**
     * array数据.
     *
     * @var array
     */
    public array $data = [];

    public function __construct(int $code, string $msg = '', array $data = [])
    {
        $this->code = $code;
        $this->msg  = $msg;
        $this->data = $data;
    }

    /**
     * new一个Result并回传.
     *
     * @param  int     $code
     * @param  string  $msg
     * @param  array   $data
     *
     * @return static
     */
    public static function make(int $code, string $msg = '', array $data = []): static
    {
        return (new static($code, $msg, $data));
    }

    /**
     * 结果是否成功.
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->code === ErrorCode::ErrNone;
    }

    /**
     * 取出array格式.
     *
     * @return array
     */
    public function toArray(): array
    {
        $r = ['code' => $this->code, 'msg' => $this->msg];
        if ( ! empty($this->data)) {
            $r['data'] = $this->data;
        }

        return $r;
    }

    public static function success(string $msg = 'ok', array $data = []): static
    {
        $msg = ! empty($msg) ? $msg : 'ok';

        return (new static(ErrorCode::ErrNone, $msg, $data));
    }

    public static function error(string $msg = '未定义错误'): static
    {
        $msg = ! empty($msg) ? $msg : '未定义错误';

        return (new static(ErrorCode::ErrUnknown, $msg));
    }

    public static function invalidParam(string $msg): static
    {
        return (new static(ErrorCode::ErrInvalidParam, $msg));
    }

    public static function notFound(string $msg): static
    {
        return (new static(ErrorCode::ErrNotFound, $msg));
    }

    public function jsonSerialize(): string
    {
        return json_encode($this->toArray());
    }

}
