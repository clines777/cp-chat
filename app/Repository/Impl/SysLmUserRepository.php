<?php

namespace App\Repository\Impl;

use App\Lib\Facade\Cache;
use App\Lib\NoSqlKey;
use Hyperf\DbConnection\Db;

/**
 * 系统红包导入用户.
 */
class SysLmUserRepository
{

    public string $table = 'sys_lm_user';

    /**
     * 添加.
     *
     * @param  array  $members
     *
     * @return int
     */
    public function add(array $members): int
    {
        if (empty($members)) {
            return 0;
        }

        $count = count($members);
        $ok    = Db::table($this->table)->insert($members);
        if ( ! $ok) {
            return 0;
        }

        return $count;
    }

    /**
     * 取红包已存在导入数据的ext_member_id
     *
     * @param  int     $lmId
     * @param  string  $siteBid
     * @param  array   $memberIds
     * @param  string  $col
     *
     * @return array
     */
    public function getExistsLmExtMId(int $lmId, string $siteBid, array $memberIds, string $col = 'ext_member_id'): array
    {
        return Db::table($this->table)->where('lm_id', $lmId)->where('site_bid', $siteBid)->whereIn('ext_member_id', $memberIds)->pluck($col)->toArray();
    }

    /**
     * 取系统红包导入用户名单.
     *
     * @param  int    $lmId
     * @param  array  $cols
     *
     * @return list<\stdClass>
     */
    public function getLmUsers(int $lmId, array $cols = ['user_id', 'is_taken']): array
    {
        return Db::table($this->table)->where('lm_id', $lmId)->select($cols)->get()->toArray();
    }

    /**
     * 取导入用户map
     *
     * @param  array  $lm
     *
     * @return array
     */
    public function getUserMap(array $lm): array
    {
        $key = NoSqlKey::sysLmUserMap($lm['id']);
        if (Cache::has($key)) {
            return Cache::get($key);
        }

        $expire = $lm['end_time'] - time();
        $list   = $this->getLmUsers($lm['id'], ['user_id', 'is_taken']);
        $map    = array_column($list, null, 'user_id');

        Cache::set($key, $map, $expire);

        return $map;
    }

    /**
     * 标记为已领取
     *
     * @param  int  $lmId
     * @param  int  $userId
     *
     * @return bool
     */
    public function updateTaken(int $lmId, int $userId): bool
    {
        Db::table($this->table)->where('lm_id', $lmId)->where('user_id', $userId)->update(['is_taken' => 1]);

        return true;
    }

}