<?php

namespace App\Lib;

/**
 * 栏位验证结果.
 */
class ValidateResult
{

    /**
     * @var bool 是否成功.
     */
    public bool $success = false;

    /**
     * 错误栏位.
     *
     * @var array
     */
    public array $errors;

    /**
     * 错误栏位资讯字串.
     *
     * @var string
     */
    public string $msg = '';

    /**
     * 是否异常.
     *
     * @var bool
     */
    public bool $isException = false;

    public array $validated = [];

    /**
     * @param  bool   $success        是否成功.
     * @param  array  $errMsgKeyVal   错误栏位资讯.
     * @param  array  $validatedData  验证通过数据.
     *
     * @return static
     */
    public static function make(bool $success, array $errMsgKeyVal = [], array $validatedData = []): static
    {
        $v              = new self();
        $v->success     = $success;
        $v->errors      = $errMsgKeyVal;
        $v->isException = ! empty($errMsgKeyVal['exception']);
        $v->validated   = $validatedData;
        if ( ! empty($errMsgKeyVal)) {
            $m = '';
            foreach ($errMsgKeyVal as $key => $val) {
                $m .= $key.' => '.implode('|', $val).',';
            }
            $v->msg = trim($m, ',');
        }

        return $v;
    }

}