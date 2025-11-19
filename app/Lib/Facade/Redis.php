<?php

namespace App\Lib\Facade;

use Hyperf\Context\ApplicationContext;
use Hyperf\Redis\RedisFactory;
use Hyperf\Redis\RedisProxy;

class Redis
{

    /**
     * 活动数据pool
     */
    public const PoolBonus = 'bonus';

    /**
     * 推送Pool
     */
    public const PoolNotify = 'notify';

    /**
     * 状态Pool
     */
    public const PoolState = 'default';

    /**
     * 缓存pool
     */
    public const PoolCache = 'cache';

    public static function instance(string $pool = 'default'): RedisProxy
    {
        return ApplicationContext::getContainer()->get(RedisFactory::class)->get($pool);
    }

    public static function set(string $key, mixed $value, int $timeout = 0): bool
    {
        return static::instance()->set($key, $value, $timeout);
    }

    public static function get(string $key): mixed
    {
        return static::instance()->get($key);
    }

    public static function del(string $key): int
    {
        return static::instance()->del($key);
    }

    public static function ttl(string $key, int $ttl): void
    {
        static::instance()->expire($key, $ttl);
    }

    public static function has(string $key): bool
    {
        return (bool)static::instance()->exists($key);
    }

    public static function lock(string $key, int $timeout = 10): bool
    {
        return (bool)static::instance()->set($key, uniqid('', true), ['NX', 'EX' => $timeout]);
    }

    public static function unlock(string $key): bool
    {
        return (bool)static::instance()->del($key);
    }

    public static function flushAll(): bool
    {
        return (bool)static::instance()->flushAll();
    }

    public static function flushDb(int $db = 0): void
    {
        static::ensureCoroutine(function () use ($db) {
            $redis = static::instance();
            $redis->select($db);
            $redis->flushDb();
        });
    }

    protected static function ensureCoroutine(\Closure $callback)
    {
        if (\Swoole\Coroutine::getCid() > 0) {
            return $callback();
        }

        \Swoole\Coroutine::create($callback);

        return true;
    }

}