<?php

namespace App\Repository\Impl;

use App\Lib\Facade\Log;
use App\Lib\Facade\Redis;
use App\Lib\Helper;
use App\Lib\NoSqlKey;
use App\Lib\Paginator;
use App\Model\Group;
use App\Model\GroupUser;
use Hyperf\DbConnection\Db;
use stdClass;

class GroupUserRepository
{

    public string $table = 'group_user';

    /**
     * 单取一笔group_user数据
     *
     * @param  int    $userId
     * @param  int    $groupId
     * @param  array  $cols
     *
     * @return array
     */
    public function getGroupUser(int $userId, int $groupId, array $cols = ['*']): array
    {
        return (array)Db::table($this->table)->where('group_id', $groupId)->where('user_id', $userId)->select($cols)->limit(1)->first();
    }

    /**
     * 取多笔群用户.
     *
     * @param  int    $groupId  群ID
     * @param  array  $userIds  用户ID, 空值时不指定用户
     *
     * @return list<stdClass>
     */
    public function getGroupUsers(int $groupId, array $userIds = [], array $cols = ['*']): array
    {
        $query = DB::table($this->table)->where('group_id', $groupId);
        if ( ! empty($userIds)) {
            $query->whereIn('user_id', $userIds);
        }

        return $query->select($cols)->get()->toArray();
    }

    /**
     * 添加群组用户.
     *
     * @param  array  $userList  ['id'=> 0, 'id' => 1, ...]
     * @param  int    $groupId   群ID
     * @param  int    $roleType  角色 1:一般用户 2.群管理 3.群主
     *
     * @return bool
     */
    public function addGroupUsers(array $userList, int $groupId, int $roleType): bool
    {
        if (empty($userList)) {
            return false;
        }

        $time    = time();
        $inserts = [];
        foreach ($userList as $user) {
            $inserts[] = [
                'group_id'    => $groupId,
                'join_time'   => $time,
                'update_time' => $time,
                'role_type'   => $roleType,
                'user_id'     => $user['id'],
            ];
        }

        return Db::table($this->table)->insert($inserts);
    }

    /**
     * 取得group join group_user单用户数据.
     *
     * @param  int    $userId   用户ID
     * @param  int    $groupId  群ID
     * @param  array  $cols     可选栏位
     *
     * @return array
     */
    public function getJoinGroup(int $groupId, int $userId, array $cols = ['group.*']): array
    {
        return (array)Db::table('group_user')->join('group', 'group.id', '=', 'group_user.group_id')->where(
            'group_user.user_id',
            $userId,
        )->where('group_user.group_id', $groupId)->select($cols)->limit(1)->first();
    }

    /**
     * 取group_user跟user join栏位.
     *
     * @param  int    $groupId
     * @param  array  $userIds
     * @param  array  $cols
     *
     * @return list<stdClass>
     */
    public function getJoinUsers(int $groupId, array $userIds = [], array $cols = ['group_user.*']): array
    {
        return Db::table('user')->leftJoin('group_user', function ($join) use ($groupId) {
            $join
                ->on('group_user.user_id', '=', 'user.id')->where('group_user.group_id', '=', $groupId); // 条件放在 ON
        })->whereIn(
            'user.id',
            $userIds,
        )->select($cols)->get()->toArray();
    }

    /**
     * 取单群在线用户, $fd大于0时取出单人.
     *
     * @param  int  $groupId  群ID
     *
     * @return array
     */
    public function rGetGroupOnlineUsers(int $groupId): array
    {
        $list = [];
        $key  = NoSqlKey::groupOnlineKey($groupId);
        if (Redis::has($key)) {
            $redis = Redis::instance();
            $list  = $redis->hGetAll($key);
        }

        if ( ! empty($list)) {
            foreach ($list as $userId => $item) {
                if ( ! is_array($item)) {
                    $list[$userId] = json_decode($item, true);
                }
            }
        }

        return (array)$list;
    }

    /**
     * @param  int  $groupId
     *
     * @return int
     */
    public function rCountGroupOnlineUsers(int $groupId): int
    {
        $users = $this->rGetGroupOnlineUsers($groupId);
        if (empty($users)) {
            return 0;
        }

        return count($users);
    }

    /**
     * @param  int  $groupId
     * @param  int  $userId
     *
     * @return array
     */
    public function rGetGroupOnlineUser(int $groupId, int $userId): array
    {
        $info = [];
        $key  = NoSqlKey::groupOnlineKey($groupId);
        if (Redis::has($key)) {
            $redis = Redis::instance();
            $info  = $redis->hGet($key, (string)$userId);
        }

        if ( ! is_array($info)) {
            $info = json_decode($info, true);
        }

        return (array)$info;
    }

    /**
     * 更新在线用户栏位值.
     *
     * @param  int    $groupId
     * @param  int    $userId
     * @param  array  $params
     *
     * @return bool
     */
    public function rSetGroupOnlineUser(int $groupId, int $userId, array $params): bool
    {
        $info = $this->rGetGroupOnlineUser($groupId, $userId);
        foreach ($params as $k => $v) {
            $info[$k] = $v;
        }

        try {
            Redis::instance()->hSet(NoSqlKey::groupOnlineKey($groupId), (string)$userId, json_encode($info));

            return true;
        } catch (\RedisException $e) {//这边只有连线异常才会失败, hSet返回值无法直接用来判断.
            Log::error("setGroupOnlineUser, redis error:".Helper::getExpDetails($e));

            return false;
        }
    }

    /**
     * 移除聊天群在线用户
     *
     * @param  int  $groupId
     * @param  int  $userId
     *
     * @return bool
     */
    public function rDelOnlineUser(int $groupId, int $userId): bool
    {
        $redis = Redis::instance();
        $ok    = $redis->hDel(NoSqlKey::groupOnlineKey($groupId), $userId);

        return $ok !== false;
    }

    /**
     * 清除群组在线状态.
     *
     * @param  int  $groupId
     *
     * @return bool
     */
    public function rDelGroupOnline(int $groupId): bool
    {
        $redis = Redis::instance();
        $ok    = $redis->del(NoSqlKey::groupOnlineKey($groupId));

        return $ok !== false;
    }

    /**
     * 更新群用户
     *
     * @param  int    $groupId
     * @param  int    $userId
     * @param  array  $updateParam
     *
     * @return bool
     */
    public function updateGroupUser(int $groupId, int $userId, array $updateParam): bool
    {
        if (empty($updateParam)) {
            return false;
        }

        $updateParam['update_time'] = time();

        return Db::table($this->table)->where('group_id', $groupId)->where('user_id', $userId)->update($updateParam);
    }

    /**
     * 直接以ID更新group_user
     *
     * @param  int    $groupUserId
     * @param  array  $updateParam
     *
     * @return bool
     */
    public function updateGroupUserById(int $groupUserId, array $updateParam): bool
    {
        if (empty($updateParam)) {
            return false;
        }
        $updateParam['update_time'] = time();

        return Db::table($this->table)->where('id', $groupUserId)->update($updateParam);
    }

    /**
     * 删除group_user数据. by id
     *
     * @param  int  $groupUserId
     *
     * @return bool
     */
    public function delGroupUser(int $groupUserId): bool
    {
        return Db::table($this->table)->where('id', $groupUserId)->delete() > 0;
    }

    /**
     * 取用户所属群组
     *
     * @param  int    $userId  用户ID
     * @param  int    $page
     * @param  int    $pageSize
     * @param  array  $cols
     *
     * @return array
     */
    public function getUserGroups(int $userId, int $page, int $pageSize, array $cols = ['g.title', 'g.my_group_cover_pic_url', 'g.code', 'g.id', 'gu.pin_time']): array
    {
        $query = Db::table($this->table.' as  gu')->join('group as g', function ($join) use ($userId) {
            $join->on('gu.group_id', '=', 'g.id')->where('gu.user_id', '=', $userId)->where('g.is_dismiss', '=', Group::DismissNo);
        })->select($cols);

        $total = $query->count();
        $query->orderBy('gu.pin_time', 'desc');
        $query->limit($pageSize)->offset(($page - 1) * $pageSize);
        $groups = $query->select($cols)->get()->toArray();

        $pagination = (new Paginator($total, $page, $pageSize));

        return [$groups, $pagination];
    }

    /**
     * 获取已在群内用户.
     *
     * @param  int    $groupId
     * @param  array  $userIds
     * @param  array  $cols
     *
     * @return list<stdClass>
     */
    public function getExistsGroupUsers(int $groupId, array $userIds, array $cols = ['user_id']): array
    {
        return Db::table($this->table)->where('group_id', $groupId)->whereIn('user_id', $userIds)->select($cols)->get()->toArray();
    }

    /**
     * @param  int  $groupId
     *
     * @return int
     */
    public function countGroupUser(int $groupId): int
    {
        return Db::table($this->table)->where('group_id', $groupId)->count();
    }

    /**
     * 取用户所属群组未读数.
     *
     * @param  int  $userId
     *
     * @return list<stdClass>
     */
    public function getUserGroupsUnreadCount(int $userId): array
    {
        return Db::table('group_user as gu')->leftJoin('chat_record as cr', function ($join) use ($userId) {
            $join->on('gu.group_id', '=', 'cr.group_id')->where('gu.last_read_chat_id', '!=', 0)->whereRaw(
                'cr.id > gu.last_read_chat_id',
            )->where('cr.deleted', '=', 0);
        })->where('gu.user_id', $userId)->groupBy('gu.group_id')->select(
            'gu.group_id as group_id',
            Db::raw('COUNT(cr.id) as unread_count'),
        )->get()->toArray();
    }

    /**
     * 更新用户最后可见ID
     *
     * @param  mixed  $groupUserId
     * @param  array  $updateParam
     *
     * @return bool
     */
    public function updateLastVisibleChat(int $groupUserId, array $updateParam): bool
    {
        Db::table($this->table)->where('id', $groupUserId)->update($updateParam);

        return true;
    }

    /**
     * 删除用户与群关系.
     *
     * @param  int  $groupId
     *
     * @return bool
     */
    public function deleteGroupUsers(int $groupId): bool
    {
        return Db::table($this->table)->where('group_id', $groupId)->where('role_type', '<', GroupUser::RoleOwner)->delete() > 0;
    }

    /**
     * @param  string  $site_bid
     * @param  int     $extMemberId
     *
     * @return list<stdClass>
     */
    public function getUserGroupIdsByExtInfo(string $site_bid, int $extMemberId): array
    {
        return Db::table('user as u')->join('group_user as gu', function ($join) use ($site_bid, $extMemberId) {
            $join->on('u.id', '=', 'gu.user_id')->where('u.site_bid', $site_bid)->where('u.ext_member_id', $extMemberId);
        })->select(['gu.user_id', 'gu.group_id'])->get()->toArray();
    }

    /**
     * 更新用户最新已读.
     *
     * @param  int  $groupUserId
     * @param  int  $chatId
     *
     * @return bool
     */
    public function updateUserLastRead(int $groupUserId, int $chatId): bool
    {
        return Db::table($this->table)->where('id', $groupUserId)->update(['last_read_chat_id' => $chatId]) > 0;
    }

    /**
     * @param  array  $toAddGroupUsers
     *
     * @return bool
     */
    public function add(array $toAddGroupUsers): bool
    {
        return Db::table($this->table)->insert($toAddGroupUsers);
    }

    public function updateRole(int $groupId, array $userIds, int $roleType): bool
    {
        Db::table($this->table)->where('group_id', $groupId)->whereIn('user_id', $userIds)->update(['role_type' => $roleType, 'update_time' => time()]);

        return true;
    }

    /**
     * 查询群组是否存在mod以上成员
     *
     * @param  int  $groupId
     *
     * @return bool
     */
    public function hasUser(int $groupId): bool
    {
        return Db::table($this->table)->where('group_id', $groupId)->where('role_type', '<', GroupUser::RoleOwner)->exists();
    }

    /**
     * 取用户全部群组
     *
     * @param  int    $userId
     * @param  array  $cols
     *
     * @return list<\stdClass>
     */
    public function getUserAllGroup(int $userId, array $cols = ['*']): array
    {
        return Db::table($this->table)->where('user_id', $userId)->select($cols)->get()->toArray();
    }

    /**
     * 以group_user.id进行删除.
     *
     * @param  array  $groupUserIds
     *
     * @return bool
     */
    public function deleteByIds(array $groupUserIds): bool
    {
        Db::table($this->table)->whereIn('id', $groupUserIds)->delete();

        return true;
    }

    /**
     * @param  int   $group_id
     * @param  bool  $isPin
     *
     * @return bool
     */
    public function updatePinGroup(int $group_id, bool $isPin = true): bool
    {
        $pinTime = $isPin ? time() : 0;
        Db::table($this->table)->where('id', $group_id)->update(['pin_time' => $pinTime]);

        return true;
    }

    /**
     * 以身分查询多笔.
     *
     * @param  int    $groupId
     * @param  int    $roleType
     * @param  array  $cols
     *
     * @return array
     */
    public function getByRole(int $groupId, int $roleType, array $cols = ['*']): array
    {
        return Db::table($this->table)->where('group_id', $groupId)->where('role_type', $roleType)->select($cols)->get()->toArray();
    }

    /**
     * 外部查询未读群组数.
     *
     * @param  int  $userId
     *
     * @return int
     */
    public function getExtUnreadCount(int $userId): int
    {
        return Db::table('group_user as gu')->where('gu.user_id', $userId)->whereExists(function ($q) {
            $q
                ->select(Db::raw(1))->from('chat_record as cr')->whereColumn('cr.group_id', 'gu.group_id')->where('cr.deleted', 0)->whereRaw(
                    'cr.id > IFNULL(gu.last_read_chat_id, 0)',
                );
        })->count();
    }

    /**
     * @param  array  $notifyGroupIds
     *
     * @return array
     */
    public function getUserCountMap(array $notifyGroupIds): array
    {
        return Db::table('group_user')->whereIn('group_id', $notifyGroupIds)->groupBy('user_id')->selectRaw('COUNT(id) as user_count, group_id')->get()->toArray();
    }

}