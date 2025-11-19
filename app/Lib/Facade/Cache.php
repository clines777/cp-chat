<?php

namespace App\Lib\Facade;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\PackerInterface;
use Psr\SimpleCache\CacheInterface;

class Cache
{

    public static function instance(): CacheInterface
    {
        return ApplicationContext::getContainer()->get(CacheInterface::class);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::instance()->get($key, $default);
    }

    public static function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        return static::instance()->set($key, $value, $ttl);
    }

    public static function del($keys): bool
    {
        if ( ! is_array($keys)) {
            $keys = (array)$keys;
        }

        $c  = static::instance();
        $ok = false;
        foreach ($keys as $key) {
            $ok = $c->delete($key);
        }

        return $ok;
    }

    public static function clear(): bool
    {
        return static::instance()->clear();
    }

    public static function has(string $key): bool
    {
        return static::instance()->has($key);
    }

    /**
     * 模仿Laravel风格的remember function
     *
     * @param  string    $cacheKey
     * @param  int       $ttl
     * @param  \Closure  $callback  cache不存在时用来做cache的数据来源.
     *
     * @return array
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public static function remember(string $cacheKey, int $ttl, \Closure $callback): mixed
    {
        $cache = static::instance();

        /** @var PackerInterface $packer */
        $packer = ApplicationContext::getContainer()->get(PackerInterface::class);

        if ($cache->has($cacheKey)) {
            $cached = $cache->get($cacheKey);
            $val    = $packer->unpack($cached);
            if ($val !== null) {
                return $packer->unpack($cached);
            }
        }

        $value = $callback();
        $cache->set($cacheKey, $packer->pack($value), $ttl);

        return $value;
    }

    /**
     * 取隨機緩存時間
     *
     * @param  int  $base
     * @param  int  $multi
     *
     * @return int
     */
    public static function randTTL(int $base = 3600, int $multi = 2): int
    {
        return rand($base, (int)($base * $multi));
    }

}