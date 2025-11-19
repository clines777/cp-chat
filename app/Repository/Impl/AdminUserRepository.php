<?php

namespace App\Repository\Impl;

use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Helper;
use App\Lib\Retrier;
use App\Model\Avatar;
use App\Model\GroupUser;
use App\Service\Impl\UserService;
use Hyperf\DbConnection\Db;

/**
 * 后台用户repo
 */
class AdminUserRepository
{

    public string $table = 'user';

    /**
     * 取后台用户列表.
     *
     * @param  int    $page
     * @param  int    $pageSize
     * @param  array  $params
     *
     * @return array
     */
    public function getAdminIndex(int $page, int $pageSize, array $params): array
    {
        $query = Db::table($this->table)->where('site_bid', $params['site_bid']);

        if (isset($params['is_global_ban']) && in_array($params['is_global_ban'], [0, 1], false)) {
            $query->where('is_global_ban', (int)$params['is_global_ban']);
        }

        if ( ! empty($params['user_id'])) {
            $query->where('id', (int)($params['user_id']));
        }

        if ( ! empty($params['code'])) {
            $query->where('code', trim($params['code']));
        }

        $total = $query->count();

        $query->select(['id', 'ext_username', 'code', 'is_global_ban', 'user_level', 'create_time', 'last_login_time', 'ext_member_id']);

        $list = $query->limit($pageSize)->offset(($page - 1) * $pageSize)->orderBy('create_time', 'desc')->get()->toArray();

        $data              = [];
        $data['total']     = $total;
        $data['page']      = $page;
        $data['page_size'] = $pageSize;
        $data['model']     = $list;

        return $data;
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
     * 后台查询group_user列表
     *
     * @param  int    $page
     * @param  int    $pageSize
     * @param  array  $params
     *
     * @return list<\stdClass>
     */
    public function getAdminGroupUserIndex(int $page, int $pageSize, array $params): array
    {
        $query = Db::table('group_user as gu')->join('group as g', 'gu.group_id', '=', 'g.id')->join('user as u', 'gu.user_id', '=', 'u.id')->where(
            'g.site_bid',
            $params['site_bid'],
        )->select(
            [
                'g.id as group_id',
                'g.title',
                'g.code as group_code',
                'gu.is_ban',
                'gu.user_id',
                'gu.role_type',
                'gu.join_time',
                'u.ext_username',
                'u.ext_member_id',
                'u.code as user_code',
            ],
        );

        if ( ! empty($params['group_id'])) {
            $query->where('g.id', (int)$params['group_id']);
        }

        if ( ! empty($params['group_code'])) {
            $query->where('g.code', trim($params['group_code']));
        }

        if (isset($params['role_type']) && in_array($params['role_type'], [GroupUser::RoleUser, GroupUser::RoleMod, GroupUser::RoleOwner])) {
            $query->where('gu.role_type', (int)$params['role_type']);
        }

        if (isset($params['is_ban']) && in_array($params['is_ban'], [GroupUser::BanNo, GroupUser::BanYes])) {
            $query->where('gu.is_ban', (int)$params['is_ban']);
        }

        if ( ! empty($params['user_code'])) {
            $query->where('u.code', trim($params['user_code']));
        }

        if ( ! empty($params['user_id'])) {
            $query->where('gu.user_id', (int)$params['user_id']);
        }

        $total = $query->count();
        $list  = [];
        try {
            $list = $query->limit($pageSize)->offset(($page - 1) * $pageSize)->orderBy('gu.join_time', 'desc')->get();
        } catch (\Throwable $e) {
            Log::error('查询出错!!!'.Helper::getExpDetails($e), 'getAdminGroupUserIndex_err');
        }

        $data              = [];
        $data['total']     = $total;
        $data['page']      = $page;
        $data['page_size'] = $pageSize;
        $data['model']     = $list->toArray();

        return $data;
    }

    /**
     * @param  array   $userData
     * @param  string  $siteBid
     *
     * @return array
     */
    public function createUserIfNotExist(array $userData, string $siteBid): array
    {
        $now        = time();
        $existsUser = (array)Db::table($this->table)->where('site_bid', $siteBid)->where('ext_member_id', $userData['member_id'])->first();
        if (empty($existsUser)) {
            /** @var \App\Service\Impl\UserService $userService */
            $userService = Container::get(UserService::class);
            $ownerCode   = Retrier::run(static function () use ($userData, $siteBid, $userService) {
                return $userService->createUserCode($userData['username'], $siteBid);
            });

            if (empty($ownerCode)) {
                throw new \PDOException('生成群主聊天号失败!');
            }

            $ownerData = [
                'site_bid'          => $siteBid,
                'ext_member_id'     => trim($userData['member_id']),
                'ext_username'      => trim($userData['username']),
                'ext_platform_type' => $userData['platform_type'],
                'user_level'        => $userData['user_level'],
                'create_time'       => $now,
                'update_time'       => $now,
                'avatar_id'         => Avatar::UserDefaultAvatarId,
                'code'              => $ownerCode,
            ];

            $newUserId  = Db::table($this->table)->insertGetId($ownerData);
            $existsUser = (array)Db::table($this->table)->where('id', $newUserId)->first();
        }

        return $existsUser;
    }

    /**
     * 查询群用户数据(group+user+group_user)
     *
     * @param  int  $groupId
     * @param  int  $userId
     *
     * @return array
     */
    public function getGroupUserInfo(int $groupId, int $userId): array
    {
        return (array)Db::table('group_user as gu')->join('group as g', 'gu.group_id', '=', 'g.id')->join('user as u', 'gu.user_id', '=', 'u.id')->where('gu.user_id', $userId)
            ->where('gu.group_id', $groupId)->select(['gu.role_type', 'gu.group_id', 'g.code', 'u.id', 'u.ext_member_id', 'u.ext_username', 'u.site_bid', 'u.user_level'])->limit(1)
            ->first();
    }

}