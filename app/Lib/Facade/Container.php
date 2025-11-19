<?php

namespace App\Lib\Facade;

use App\Lib\Helper;
use Hyperf\Context\ApplicationContext;

/**
 * container facade
 */
class Container
{

    /**
     * (单例)从容器中初始化物件实体.
     *
     * @param  string  $class
     *
     * @return mixed|null
     */
    public static function get(string $class)
    {
        try {
            return ApplicationContext::getContainer()->get($class);
        } catch (\Throwable $e) {
            Log::error(sprintf("Container::get()异常! 物件: %s, 讯息: %s", $class, Helper::getExpDetails($e)), 'container_get_err');

            return null;
        }
    }

    /**
     * (非单例, 状态不会残留)从容器中初始化物件实体
     *
     * @param  string  $class
     * @param  array   $parameters
     *
     * @return null
     */
    public static function make(string $class, array $parameters = [])
    {
        try {
            return ApplicationContext::getContainer()->make($class, $parameters);
        } catch (\Throwable $e) {
            Log::error(sprintf("Container::make()异常! 物件: %s, 参数: %s, 讯息: %s", $class, json_encode($parameters, 256), Helper::getExpDetails($e)), 'container_make_err');

            return null;
        }
    }

}