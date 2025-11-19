<?php

namespace App\Lib;

use App\Lib\Facade\Log;
use co;

class Retrier
{

    /**
     * 重试一个closure并比对其返回的值跟$resultShouldBe参数是否完全相等.
     *
     * @param  \Closure  $callBack    需返回一个array, [(bool)成功与否, (mixed)callBack要返回的执行结果]
     * @param  int       $times       最大重试次数, 默认3次, 不可超过10次
     * @param  int       $intervalMs  间隔ms, 默认500ms
     *
     * @return mixed
     */
    public static function run(\Closure $callBack, int $times = 3, int $intervalMs = 50): mixed
    {
        $maxTimes = 10;

        $attempt = 0;
        if ($times > $maxTimes) {
            $times = 3;
        }

        while ($attempt < $times) {
            $attempt++;

            try {
                [$success, $val] = $callBack();
                if ($success) {
                    return $val;
                }
            } catch (\Throwable $e) {
                Log::error(sprintf('重试器执行异常! 第%d轮, 讯息: %s', $attempt, Helper::getExpDetails($e)), 'retrier_error');

                return false;
            }

            if ($attempt < $times && $intervalMs > 0) {
                co::sleep($intervalMs / 1000);
            }
        }

        return false;
    }

}