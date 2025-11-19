<?php

namespace App\Repository\Impl;

use App\Lib\Facade\Cache;
use App\Lib\Facade\Redis;
use App\Lib\NoSqlKey;
use App\Model\LuckyMoney;
use Hyperf\DbConnection\Db;

/**
 * 红包
 */
class LuckyMoneyRepository
{

    public string $table = 'lucky_money';

    /**
     * 添加红包
     *
     * @param  array  $info
     *
     * @return array 红包
     */
    public function insertAndGet(array $info): array
    {
        if (empty($info)) {
            return [];
        }

        $inserted = [];
        $id       = Db::table($this->table)->insertGetId($info);
        if ($id) {
            $inserted = (array)Db::table($this->table)->where('id', $id)->first();
        }

        return $inserted;
    }

    /**
     * 取单笔活动.
     *
     * @param  int    $id
     * @param  array  $cols
     *
     * @return array
     */
    public function getOne(int $id, array $cols = ['*']): array
    {
        $key = NoSqlKey::luckyMoneyInfo($id);
        if (Cache::has($key)) {
            return Cache::get($key);
        }

        $lm = (array)Db::table($this->table)->where('id', $id)->select($cols)->first();
        Cache::set($key, $lm, Cache::randTTL());

        return $lm;
    }

    /**
     * 更新.
     *
     * @param  int    $id
     * @param  array  $updateFields
     *
     * @return bool
     */
    public function update(int $id, array $updateFields): bool
    {
        if ( ! Db::table($this->table)->where('id', $id)->exists()) {
            return false;
        }

        $updateFields['update_time'] = time();

        return DB::table($this->table)->where('id', $id)->update($updateFields) > 0;
    }

    /**
     * 标记用户已抢过红包
     *
     * @param  int  $lmId
     * @param  int  $userId
     *
     * @return bool
     */
    public function rSetUserTakenFlag(int $lmId, int $userId): bool
    {
        $key   = NoSqlKey::luckyMoneyFlag($lmId);
        $redis = Redis::instance(Redis::PoolBonus);

        return (bool)$redis->hSet($key, $userId, 1);
    }

    /**
     * 查询用户是否已抢过红包
     *
     * @param  int  $lmId
     * @param  int  $userId
     *
     * @return bool
     */
    public function rHasUserTakenLuckyMoney(int $lmId, int $userId): bool
    {
        $key   = NoSqlKey::luckyMoneyFlag($lmId);
        $redis = Redis::instance(Redis::PoolBonus);

        return $redis->hExists($key, (string)$userId);
    }

    /**
     * 初始化抢红包的hash
     *
     * @param  array  $luckyMoney
     *
     * @return bool
     */
    public function rInitLuckyMoneyFlag(array $luckyMoney): bool
    {
        $untilEnd = $luckyMoney['end_time'] - time();
        if ($untilEnd < 0) {
            return false;
        }

        $key   = NoSqlKey::luckyMoneyFlag($luckyMoney['id']);
        $redis = Redis::instance(Redis::PoolBonus);
        $redis->hSet($key, '_init', 1);//初始化一个用不到的空值

        return $redis->expire($key, $untilEnd);
    }

    /**
     * 刪除redis領取紀錄
     *
     * @param  int  $lmId
     *
     * @return bool
     */
    public function rDelTakenFlag(int $lmId): bool
    {
        $key   = NoSqlKey::luckyMoneyFlag($lmId);
        $redis = Redis::instance(Redis::PoolBonus);

        return (bool)$redis->del($key);
    }

    /**
     * 捞出未关闭红包活动.
     *
     * @param  array  $cols
     *
     * @return list<\stdClass>
     */
    public function getOpenLmList(array $cols = ['*']): array
    {
        return Db::table($this->table)->where('close_type', LuckyMoney::CloseTypeNone)->select($cols)->get()->toArray();
    }

}