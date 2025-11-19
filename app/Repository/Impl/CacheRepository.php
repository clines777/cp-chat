<?php

namespace App\Repository\Impl;

use App\Lib\Facade\Cache;

class CacheRepository
{

    /**
     * 清缓存 by cache key.
     *
     * @param  string  $key
     *
     * @return bool
     */
    public function clearByKey(string $key): bool
    {
        Cache::del($key);

        return true;
    }

    /**
     * 清除全部缓存.
     *
     * @return void
     */
    public function clearAll(): void
    {
        Cache::clear();
    }

}