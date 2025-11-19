<?php

namespace App\Repository\Impl;

use App\Lib\Facade\Redis;
use App\Lib\NoSqlKey;
use Hyperf\DbConnection\Db;

/**
 * 跑马灯repo
 */
class MarqueeRepository
{

    public string $table = 'marquee';

    /**
     * 储存.
     *
     * @param  array  $marquee
     *
     * @return int
     */
    public function saveAndGetId(array $marquee): int
    {
        $id = Db::table($this->table)->insertGetId($marquee);
        if (empty($id)) {
            return 0;
        }

        return $id;
    }

    /**
     * 取单笔.
     *
     * @param  int  $id
     *
     * @return array
     */
    public function getOne(int $id): array
    {
        return (array)Db::table($this->table)->where('id', $id)->first();
    }

    /**
     * 缓存跑马灯讯息
     *
     * @param  array  $marquee
     * @param  int    $ttl
     *
     * @return bool
     */
    public function rSetMarquee(array $marquee, int $ttl): bool
    {
        $json = json_encode($marquee);
        $key  = NoSqlKey::marquee();

        return Redis::set($key, $json, $ttl);
    }

    /**
     * 取得跑马灯资讯.
     *
     * @return array
     */
    public function rGetMarquee(): array
    {
        $key = NoSqlKey::marquee();
        if ( ! Redis::has($key)) {
            return [];
        }

        $json = Redis::get($key);

        return ! empty($json) && json_validate($json) ? json_decode($json, true) : [];
    }

    /**
     * 取出上次發過的跑馬燈ID
     *
     * @return int
     */
    public function rGetLastId(): int
    {
        $id  = 0;
        $key = NoSqlKey::lastMarqueeId();
        if (Redis::has($key)) {
            $id = (int)Redis::get($key);
        }

        return $id;
    }

    /**
     * @param  int  $id
     *
     * @return bool
     */
    public function rSetLastId(int $id): bool
    {
        $key = NoSqlKey::lastMarqueeId();

        return Redis::set($key, $id, 86400 * 30);
    }

}