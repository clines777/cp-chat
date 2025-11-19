<?php

namespace App\Service\Impl;

use App\Lib\CommonResult;
use App\Lib\Facade\Container;
use App\Lib\NoSqlKey;
use App\Repository\Impl\CacheRepository;

class CacheService
{

    /**
     * 清除缓存.
     *
     * @param  array  $param
     *
     * @return \App\Lib\CommonResult
     */
    public function clear(array $param): CommonResult
    {
        $key = ! empty($param['key']) ? trim($param['key']) : '';
        if ($key !== '' && ! isset($this->getAllowKeyMap()[$key])) {
            return CommonResult::error('不合法的cache key');
        }

        /** @var CacheRepository $cacheRepo */
        $cacheRepo = Container::get(CacheRepository::class);

        if ($key === '') {
            $cacheRepo->clearAll();
        } else {
            $cacheRepo->clearByKey($key);
        }

        return CommonResult::success(sprintf('已清除 %s 缓存', $key));
    }

    /**
     * @return array
     */
    protected function getAllowKeyMap(): array
    {
        return [NoSqlKey::config() => true, NoSqlKey::siteHost() => true, NoSqlKey::avatarMap() => true, NoSqlKey::serverOpen() => true, NoSqlKey::siteRKeyMap() => true];
    }

}