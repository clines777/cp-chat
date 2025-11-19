<?php

namespace App\Repository\Impl;

use App\Lib\Facade\Cache;
use App\Lib\NoSqlKey;
use App\Model\User;
use Hyperf\DbConnection\Db;
use stdClass;

use function Hyperf\Config\config;

class UserRepository
{

    /**
     * @var string
     */
    public string $table = 'user';

    /**
     * 取单笔
     *
     * @param  int    $userId  用户ID
     * @param  array  $cols
     *
     * @return array
     */
    public function getOne(int $userId, array $cols = ['*']): array
    {
        return (array)Db::table($this->table)->where('id', $userId)->select($cols)->limit(1)->first();
    }

    /**
     * 插入一筆資料，回傳是否成功。
     */
    public function insertAndGet(array $data): array
    {
        $id = Db::table($this->table)->insertGetId($data);
        if ( ! $id) {
            return [];
        }

        return $this->getOne($id, ['*'], false);
    }

    /**
     * 更新单笔用户.
     *
     * @param  int    $id
     * @param  array  $data
     *
     * @return array
     */
    public function updateAndGet(int $id, array $data): array
    {
        $updated = Db::table($this->table)->where('id', $id)->update($data) > 0;
        if ( ! $updated) {
            return [];
        }

        return $this->getOne($id, ['*'], false);
    }

    /**
     * 先缓存用户登入用Token等数据, 等实际进入聊天室时再验证Token有效性.
     *
     * @param  array   $cacheInfo
     * @param  string  $token  登入Token
     *
     * @return bool
     */
    public function cSaveLoginToken(array $cacheInfo, string $token): bool
    {
        return Cache::set(NoSqlKey::loginTokenKey($token), $cacheInfo, config('business.login_token_ttl'));
    }

    /**
     * 根据自订条件跟栏位取出单笔用户. 注意一下条件有没有索引
     *
     * @param  array  $conditions  栏位跟值的key value pair eg. ['site_bid' => 'B666', 'ext_member_id' => 567354]
     * @param  array  $cols
     *
     * @return array
     */
    public function getOneUserByCondition(array $conditions, array $cols = ['*']): array
    {
        if (empty($conditions)) {
            return [];
        }

        $query = Db::table($this->table);
        foreach ($conditions as $key => $value) {
            if (is_array($value)) {
                $query->whereIn($key, $value);
            } else {
                $query->where($key, $value);
            }
        }

        $user = $query->select($cols)->limit(1)->first();
        if (empty($user)) {
            return [];
        }

        return (array)$user;
    }

    /**
     * 根据条件取复数用户, 注意一下条件有没有索引
     *
     * @param  array  $conditions
     * @param  array  $cols
     *
     * @return list<stdClass>
     */
    public function getUsersByCondition(array $conditions, array $cols = ['*']): array
    {
        $query = Db::table($this->table);
        if ( ! empty($conditions)) {
            foreach ($conditions as $key => $value) {
                if (is_array($value)) {
                    $query->whereIn($key, $value);
                } else {
                    $query->where($key, $value);
                }
            }
        }

        return $query->select($cols)->orderBy('id')->get()->toArray();
    }

    /**
     * 设置头像.
     *
     * @param  int  $userId
     * @param  int  $avatarId
     *
     * @return bool
     */
    public function updateAvatar(int $userId, int $avatarId): bool
    {
        $user = $this->getOneUserByCondition(['id' => $userId]);
        if (empty($user)) {
            return false;
        }

        $ok = true;
        if ((int)$user['avatar_id'] !== $avatarId) {
            $ok = Db::table($this->table)->where('id', $userId)->update(['avatar_id' => $avatarId]);
        }

        return $ok;
    }

    /**
     * 全域禁言.
     *
     * @param  array  $user
     *
     * @return bool
     */
    public function setGlobalBanned(array $user): bool
    {
        $ok = true;
        if ( ! empty($user) && (int)$user['is_global_ban'] !== User::IsGlobalBanYes) {
            $ok = Db::table($this->table)->where('id', $user['id'])->update(['is_global_ban' => User::IsGlobalBanYes]);
        }

        return $ok;
    }

    /**
     * 解除全域禁言.
     *
     * @param  array  $user
     *
     * @return bool
     */
    public function setGlobalUnbanned(array $user): bool
    {
        $ok = true;
        if ( ! empty($user) && (int)$user['is_global_ban'] !== User::IsGlobalBanNo) {
            $ok = Db::table($this->table)->where('id', $user['id'])->update(['is_global_ban' => User::IsGlobalBanNo]);
        }

        return $ok;
    }

    /**
     * 查出站点已存在群聊用户
     *
     * @param  string  $siteBid
     * @param  array   $memberIds
     *
     * @return list<stdClass>
     */
    public function getSiteExistsUsers(string $siteBid, array $memberIds): array
    {
        return Db::table($this->table)->where('site_bid', $siteBid)->whereIn('ext_member_id', $memberIds)->select(['id as user_id', 'ext_member_id'])->get()->toArray();
    }

    /**
     * @param  array  $inserts
     *
     * @return bool
     */
    public function batchAdd(array $inserts): bool
    {
        if (empty($inserts)) {
            return false;
        }

        return Db::table($this->table)->insert($inserts);
    }

    /**
     * @param  string  $code
     *
     * @return bool
     */
    public function codeExists(string $code): bool
    {
        return Db::table($this->table)->where('code', $code)->exists();
    }

    /**
     * 批量写入用户.
     *
     * @param  array  $users
     *
     * @return bool
     */
    public function inserts(array $users): bool
    {
        if (empty($users)) {
            return false;
        }

        return Db::table($this->table)->insert($users);
    }

    /**
     * 更新系统消息最新已读.
     *
     * @param  int  $userId
     * @param  int  $chatId
     *
     * @return bool
     */
    public function updateSysLastRead(int $userId, int $chatId): bool
    {
        return Db::table($this->table)->where('id', $userId)->update(['sys_chat_last_read_id' => $chatId]) > 0;
    }

    /**
     * @param  string  $siteBid
     * @param  int     $memberId
     *
     * @return array
     */
    public function extQueryUser(string $siteBid, int $memberId): array
    {
        $key = NoSqlKey::extQueryUserId($siteBid, $memberId);
        if (Cache::has($key)) {
            return Cache::get($key);
        }

        $user = (array)Db::table($this->table)->where('site_bid', $siteBid)->where('ext_member_id', $memberId)->select(['id', 'sys_chat_last_read_id', 'site_bid', 'ext_member_id'])
            ->limit(1)->first();
        $user = empty($user) ? [] : $user;

        Cache::set($key, $user, Cache::randTTL());

        return $user;
    }

}