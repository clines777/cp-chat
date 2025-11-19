<?php

namespace App\Repository\Impl;

use App\Lib\Facade\Redis;
use App\Lib\NoSqlKey;
use App\Lib\SysConst;
use App\Model\LuckyMoneyPack;
use Hyperf\DbConnection\Db;

/**
 * 拆分的紅包數據
 */
class LuckyMoneyPackRepository
{

    public string $table = 'lucky_money_pack';

    /**
     * 批次寫入拆分好的紅包.
     *
     * @param  int    $lmId
     * @param  array  $packs
     *
     * @return list<\stdClass>
     */
    public function insertPacksAndGet(int $lmId, array $packs): array
    {
        if (empty($packs)) {
            return [];
        }

        $now = time();
        foreach ($packs as $idx => $pack) {
            $packs[$idx]['lm_id']       = $lmId;
            $packs[$idx]['create_time'] = $now;
        }

        $ok = Db::table($this->table)->insert($packs);
        if ( ! $ok) {
            return [];
        }

        return Db::table($this->table)->where('lm_id', $lmId)->get()->toArray();
    }

    /**
     * 将拆分红包存成redis list
     *
     * @param  array  $lm
     * @param  array  $packList
     *
     * @return int
     */
    public function rSaveLmPackages(array $lm, array $packList): int
    {
        $key       = NoSqlKey::luckyMoneyPackages($lm['id']);
        $redis     = Redis::instance(Redis::PoolBonus);
        $count     = count($packList);
        $packBatch = array_chunk($packList, 200);
        foreach ($packBatch as $packs) {
            shuffle($packs);
            foreach ($packs as $each) {
                $p = ['id' => $each->id, 'no' => $each->no, 'amount' => (string)$each->amount];
                $redis->lpush($key, json_encode($p, JSON_UNESCAPED_UNICODE));
            }
        }

        $ttl = $lm['end_time'] - time();
        if ($ttl < 1) {
            return 0;
        }

        $redis->expire($key, $ttl);
        if ( ! $redis->exists($key)) {
            return 0;
        }

        return $count;
    }

    /**
     * 更新为已被领取
     *
     * @param  int  $userId
     * @param  int  $packId
     *
     * @return bool
     */
    public function updateAsTaken(int $userId, int $packId): bool
    {
        return Db::table($this->table)->where('id', $packId)->where('is_taken', LuckyMoneyPack::IsTakenNo)->update(
            ['user_id' => $userId, 'is_taken' => LuckyMoneyPack::IsTakenYes, 'update_time' => time()],
        );
    }

    /**
     * 把领取失败的红包再推回去
     *
     * @param  int    $luckyMoneyId
     * @param  array  $pack
     *
     * @return bool
     */
    public function rAddLmPackBack(int $luckyMoneyId, array $pack): bool
    {
        $key   = NoSqlKey::luckyMoneyPackages($luckyMoneyId);
        $redis = Redis::instance(Redis::PoolBonus);
        if ( ! $redis->exists($key)) {
            return false;
        }

        $redis->lPush($key, json_encode($pack));

        return true;
    }

    /**
     * @param $lmId
     *
     * @return array
     */
    public function rGetLuckyMoneyPacks($lmId): array
    {
        $key   = NoSqlKey::luckyMoneyPackages($lmId);
        $redis = Redis::instance(Redis::PoolBonus);
        if ( ! $redis->exists($key)) {
            return [];
        }

        $list = [];
        $val  = $redis->lrange($key, 0, -1);
        if ( ! empty($val)) {
            foreach ($val as $each) {
                $list[] = json_decode($each, true);
            }
        }

        return $list;
    }

    /**
     * @param  int  $id
     *
     * @return array
     */
    public function getOne(int $id): array
    {
        return (array)Db::table($this->table)->where('id', $id)->first();
    }

    /**
     * 删除红包拆包列表
     *
     * @param  mixed  $lmId
     *
     * @return bool
     */
    public function rDelLmPackages(int $lmId): bool
    {
        $key   = NoSqlKey::luckyMoneyPackages($lmId);
        $redis = Redis::instance(Redis::PoolBonus);

        return (bool)$redis->del($key);
    }

    /**
     * 統計紅包拆包數量.
     *
     * @param  int  $lmId
     *
     * @return array
     */
    public function getByLm(int $lmId): array
    {
        return Db::table($this->table)->where('lm_id', $lmId)->get()->toArray();
    }

    /**
     * 统计红包状态.
     *
     * @param  int  $lmId
     *
     * @return array [(int, 总包数)$totalCount, (int, 已领取包数)$takenCount, (string, 总金额)$totalAmount, (string, 已领取金额)$takenAmount]
     */
    public function getLmState(int $lmId): array
    {
        $packs = $this->getByLm($lmId);
        if (empty($packs)) {
            return [0, 0, 0, 0];
        }

        $totalCount  = 0;
        $takenCount  = 0;
        $totalAmount = 0;
        $takenAmount = 0;
        foreach ($packs as $pack) {
            /** @var \stdClass $pack */
            ++$totalCount;
            $totalAmount = bcadd($totalAmount, (string)$pack->amount, SysConst::MoneyDecimal);
            if ($pack->is_taken > 0) {
                ++$takenCount;
                $takenAmount = bcadd($takenAmount, (string)$pack->amount, SysConst::MoneyDecimal);
            }
        }

        return [$totalCount, $takenCount, $totalAmount, $takenAmount];
    }

    /**
     * 统计红包剩余金额.
     *
     * @param  int  $lmId
     *
     * @return string
     */
    public function sumLmBalance(int $lmId): string
    {
        return (string)Db::table($this->table)->where('lm_id', $lmId)->where('is_taken', 0)->selectRaw('IFNULL(SUM(`amount`), 0) AS r')->value('r');
    }

}