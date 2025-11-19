<?php

namespace App\Service\Impl;

use App\Lib\CommonResult;
use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Facade\Validator;
use App\Lib\Helper;
use App\Lib\RStream;
use App\Lib\SysConst;
use App\Lib\UserLogBuilder;
use App\Model\Avatar;
use App\Model\GroupUser;
use App\Repository\Impl\AdminGroupRepository;
use App\Repository\Impl\AdminUserRepository;
use App\Repository\Impl\GroupRepository;
use App\Repository\Impl\GroupUserRepository;
use App\Repository\Impl\UserLogRepository;
use App\Repository\Impl\UserRepository;
use Hyperf\DbConnection\Db;

class AdminUserService
{

    protected AdminUserRepository $repo;

    public function __construct(AdminUserRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * 取后台用户列表.
     *
     * @param  array  $params
     *
     * @return \App\Lib\CommonResult
     */
    public function getUserAdminIndex(array $params): CommonResult
    {
        $page     = isset($params['page']) ? (int)$params['page'] : 1;
        $pageSize = isset($params['page_size']) ? (int)$params['page_size'] : SysConst::AdminIndexPageSize;
        if ( ! empty($params['username'])) {
            $user = $this->repo->getOneUserByCondition(['site_bid' => $params['site_bid'], 'ext_username' => trim($params['username'])], ['id']);
            if (empty($user)) {
                return CommonResult::make(ErrorCode::ErrNotFound, '查无数据');
            }
            $params['user_id'] = (int)$user['id'];
        }

        $list = $this->repo->getAdminIndex($page, $pageSize, $params);

        return CommonResult::success('ok', $list);
    }

    /**
     * 后台查询用户所属群组.
     *
     * @param  array  $params
     *
     * @return \App\Lib\CommonResult
     */
    public function getAdminGroupUserIndex(array $params): CommonResult
    {
        $page     = isset($params['page']) ? (int)$params['page'] : 1;
        $pageSize = isset($params['page_size']) ? (int)$params['page_size'] : SysConst::AdminIndexPageSize;

        if ( ! empty($params['username'])) {
            $user = $this->repo->getOneUserByCondition(['ext_username' => trim($params['username']), 'site_bid' => $params['site_bid']], ['id']);
            if (empty($user)) {
                return CommonResult::make(ErrorCode::ErrNotFound, '查无数据');
            }
            $params['user_id'] = (int)$user['id'];
        }

        $list = $this->repo->getAdminGroupUserIndex($page, $pageSize, $params);
        /** @var AdminGroupRepository $adminGroupRepo */
        $adminGroupRepo     = Container::get(AdminGroupRepository::class);
        $list['group_menu'] = $adminGroupRepo->getAdminGroupMenu($params['site_bid'], ['id', 'title', 'code']);

        return CommonResult::success('ok', $list);
    }

    /**
     * @param  array  $params
     *
     * @return \App\Lib\CommonResult
     */
    public function addUsersToGroups(array $params): CommonResult
    {
        if (empty($params['member']) || count($params['member']) > 500) {
            return CommonResult::make('每批次仅允许添加500笔用户进群');
        }

        $vResult = Validator::validate($params, [
            'member'   => [
                'required',
                'array',
                function ($attribute, $value, $fail) {
                    foreach ($value as $each) {
                        if (empty($each['member_id']) || empty($each['username']) || empty($each['platform_type']) || ! isset($each['user_level'])) {
                            $fail('member参数阵列中缺少member_id或username或user_level或platform_type栏位');
                        }
                    }
                },
            ],
            'site_bid' => 'required|string',
            'group_id' => 'required|integer|min:0',
            'admin_id' => 'integer',
        ]);

        if ( ! $vResult->success) {
            return CommonResult::invalidParam("参数验证错误!".$vResult->msg);
        }

        $params['site_bid'] = trim($params['site_bid']);
        //先检查群组是否存在.
        /** @var GroupRepository $groupRepo */
        $groupRepo = Container::get(GroupRepository::class);
        $group     = $groupRepo->getById($params['group_id']);
        if (empty($group)) {
            return CommonResult::make(ErrorCode::ErrGroupNotExists, '群组不存在');
        }

        $shouldAddUser = true;
        //查出已注册用户. 过滤中哪些用户需要添加到user表
        /** @var UserRepository $userRepo */
        $userRepo    = Container::get(UserRepository::class);
        $existsUsers = $userRepo->getSiteExistsUsers($params['site_bid'], array_column($params['member'], 'member_id'));
        if (count($existsUsers) === count($params['member'])) {
            $shouldAddUser = false;
        }
        $allAddGroupUserIds = array_column($existsUsers, 'user_id');

        $logs          = [];
        $time          = time();
        $groupUserRepo = null;
        Db::beginTransaction();
        try {
            if ($shouldAddUser) {
                $existsUserByExtMemberId = [];
                if ( ! empty($existsUsers)) {
                    $existsUserByExtMemberId = array_column($existsUsers, null, 'ext_member_id');
                }

                $membersToAdd = [];
                foreach ($params['member'] as $member) {
                    if ( ! isset($existsUserByExtMemberId[$member['member_id']])) {
                        $membersToAdd[] = $member;
                    }
                }

                $toInsertUsers = [];
                $addUserOk     = false;
                if ( ! empty($membersToAdd)) {
                    foreach ($membersToAdd as $m) {
                        $code            = Helper::genUserCode($m['username'], $params['site_bid']);
                        $toInsertUsers[] = [
                            'ext_member_id'     => (int)$m['member_id'],
                            'ext_username'      => trim($m['username']),
                            'ext_platform_type' => (int)$m['platform_type'],
                            'site_bid'          => $params['site_bid'],
                            'user_level'        => (int)$m['user_level'],
                            'create_time'       => $time,
                            'update_time'       => $time,
                            'code'              => $code,
                            'avatar_id'         => Avatar::UserDefaultAvatarId,
                        ];
                    }
                    $addUserOk = $userRepo->batchAdd($toInsertUsers);
                }
                if ( ! $addUserOk) {
                    throw new \PDOException('导入时写入user表失败');
                }

                //完成user写入后用同样参数再捞出刚写入user.id并跟已存在用户user id合并准备写入group_user
                $insertedUsers     = [];
                $insertedMemberIds = array_column($toInsertUsers, 'ext_member_id');
                if ( ! empty($insertedMemberIds)) {
                    $insertedUsers = $userRepo->getSiteExistsUsers($params['site_bid'], $insertedMemberIds);
                }

                foreach ($insertedUsers as $each) {
                    $allAddGroupUserIds[] = $each->user_id;
                }
            }

            //先捞出已在群中用户user id, 过滤出要添加进群组的user id
            /** @var GroupUserRepository $groupUserRepo */
            $groupUserRepo = Container::get(GroupUserRepository::class);
            $inGroupUsers  = $groupUserRepo->getExistsGroupUsers($group['id'], $allAddGroupUserIds);

            if ( ! empty($inGroupUsers)) {
                $inGroupUserMap = [];
                foreach ($inGroupUsers as $each) {
                    $inGroupUserMap[$each->user_id] = true;
                }
            }

            $addGroupUserIds = [];
            foreach ($allAddGroupUserIds as $eachUserId) {
                if ( ! isset($inGroupUserMap[$eachUserId])) {
                    $addGroupUserIds[] = $eachUserId;
                }
            }

            $groupUserCount = $groupUserRepo->countGroupUser($group['id']);
            if (($groupUserCount + count($addGroupUserIds)) > $group['user_limit']) {
                return CommonResult::error(sprintf('群人数已超过设定上限%d人', $group['user_limit']));
            }

            $addGroupUsers = [];
            if ( ! empty($addGroupUserIds)) {
                $userIds = [];
                //组建批量写入group_user数据

                foreach ($addGroupUserIds as $toAddUserId) {
                    $userIds[]       = $toAddUserId;
                    $addGroupUsers[] = ['id' => $toAddUserId];
                }

                /** @var UserRepository $userRepo */
                $userRepo = Container::get(UserRepository::class);
                $logUsers = $userRepo->getUsersByCondition(['id' => $userIds], ['id', 'ext_member_id', 'ext_username']);
                $logTpl   = [
                    'type'          => UserLogBuilder::TypeAdminImportToGroup,
                    'site_bid'      => $group['site_bid'],
                    'scene'         => UserLogBuilder::SceneCpAdmin,
                    'admin_id'      => $params['admin_id'],
                    'operator_type' => UserLogBuilder::OperatorTypeOther,
                    'create_time'   => $time,
                    'remark'        => '后台邀请进群，群号: '.$group['code'],
                ];
                foreach ($logUsers as $user) {
                    $log                  = $logTpl;
                    $log['user_id']       = $user->id;
                    $log['ext_member_id'] = $user->ext_member_id;
                    $log['ext_username']  = $user->ext_username;
                    $logs[]               = $log;
                }

                /** @var GroupUserRepository $groupUserRepo */
                $groupUserRepo = Container::get(GroupUserRepository::class);
                $ok            = $groupUserRepo->addGroupUsers($addGroupUsers, $group['id'], GroupUser::RoleUser);
                if ( ! $ok) {
                    throw new \PDOException('导入时写入group_user失败!');
                }
            }

            $addGroupUsersCount = count($addGroupUsers);
            if ($addGroupUsersCount < 1) {
                $result = CommonResult::success('名单中用户皆已在群组中');
            } else {
                $result = CommonResult::success(sprintf('已添加 %d 个群用户', $addGroupUsersCount));
            }

            if ( ! empty($logs)) {
                /** @var UserLogRepository $userLogRepo */
                $userLogRepo = Container::get(UserLogRepository::class);
                $userLogRepo->addWithParam($logs);
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            Log::error("导入群聊用户异常! 讯息:".Helper::getExpDetails($e), 'addUsersToGroups_err');
            $result = CommonResult::error('导入失败');
        }

        if ($groupUserRepo !== null) {
            $userCount = $groupUserRepo->countGroupUser($group['id']);
            RStream::push(RStream::TypeNotifyGroupState, ['group_id' => $group['id'], 'user_count' => $userCount]);
        }

        return $result;
    }

    /**
     * 取用户日志.
     *
     * @param  array  $params
     *
     * @return \App\Lib\CommonResult
     */
    public function getAdminUserLogs(array $params): CommonResult
    {
        if (empty($params['site_bid'])) {
            return CommonResult::error('缺少site_bid参数');
        }

        /** @var UserLogRepository $userLogRepo */
        $userLogRepo = Container::get(UserLogRepository::class);

        $page     = isset($params['page']) ? (int)$params['page'] : 1;
        $pageSize = isset($params['page_size']) ? (int)$params['page_size'] : SysConst::AdminIndexPageSize;

        if ( ! empty($params['username'])) {
            /** @var UserRepository $userRepo */
            $userRepo = Container::get(UserRepository::class);
            $user     = $userRepo->getOneUserByCondition(['ext_username' => trim($params['username']), 'site_bid' => $params['site_bid']], ['id']);
            if (empty($user)) {
                return CommonResult::success('查无相关数据');
            }
            $params['user_id'] = (int)$user['id'];
        }

        $list = $userLogRepo->getAdminIndex($page, $pageSize, $params);

        return CommonResult::success('ok', $list);
    }

    /**
     * 切换群用户身份.
     *
     * @param  array  $params
     *
     * @return \App\Lib\CommonResult
     */
    public function toggleGroupUserRole(array $params): CommonResult
    {
        $vResult = Validator::validate($params, ['group_id' => 'required|integer', 'user_id' => 'required|integer']);
        if ( ! $vResult->success) {
            return CommonResult::invalidParam('参数错误:'.$vResult->msg);
        }

        /** @var AdminUserRepository $adminUserRepo */
        $adminUserRepo = Container::get(AdminUserRepository::class);
        $groupUser     = $adminUserRepo->getGroupUserInfo($params['group_id'], $params['user_id']);
        if (empty($groupUser)) {
            return CommonResult::error('未查找到该群用户');
        }
        $groupUser['role_type'] = (int)$groupUser['role_type'];
        if ($groupUser['role_type'] === GroupUser::RoleOwner) {
            return CommonResult::make(ErrorCode::ErrInvalidOperate, '当前对象身份为群主，不可操作');
        }

        /** @var UserLogBuilder $logBuilder */
        $logBuilder = Container::make(UserLogBuilder::class, ['siteBid' => $groupUser['site_bid']]);
        if ($groupUser['role_type'] === GroupUser::RoleMod) {
            $toRole = GroupUser::RoleUser;
            $log    = $logBuilder
                ->setLogType(
                    UserLogBuilder::TypeDownToGroupUser,
                )->setParam($groupUser['id'], $groupUser['ext_member_id'], $groupUser['ext_username'])->byCpAdmin($params['admin_id'])->withRemark(
                    '降为一般成员，群号: '.$groupUser['code'],
                )->toArray();
        } else {
            $toRole = GroupUser::RoleMod;
            $log    = $logBuilder
                ->setLogType(
                    UserLogBuilder::TypeBecomeGroupAdmin,
                )->setParam($groupUser['id'], $groupUser['ext_member_id'], $groupUser['ext_username'])->byCpAdmin($params['admin_id'])->withRemark(
                    '成为群管理，群号: '.$groupUser['code'],
                )->toArray();
        }

        Db::beginTransaction();
        try {
            /** @var GroupUserRepository $groupUserRepo */
            $groupUserRepo = Container::get(GroupUserRepository::class);
            $groupUserRepo->updateRole($groupUser['group_id'], [$groupUser['id']], $toRole);

            if (isset($log)) {
                /** @var UserLogRepository $userLogRepo */
                $userLogRepo = Container::get(UserLogRepository::class);
                $userLogRepo->addWithParam($log);
            }

            Db::commit();
            $result = CommonResult::success();
        } catch (\Throwable $e) {
            Db::rollBack();
            $msg = sprintf('切换群用户身份异常! 讯息: %s', Helper::getExpDetails($e));
            Log::error($msg, 'toggleGroupUserRole_err');
            $result = CommonResult::error($msg);
        }

        $title    = Helper::buildChatUserTitle($toRole, $groupUser['user_level']);
        $userData = ['group_id' => $groupUser['group_id'], 'user_id' => $groupUser['id'], 'role_type' => $toRole, 'user_level' => $groupUser['user_level'], 'title' => $title];
        RStream::push(RStream::TypeNotifyUserRoleChange, $userData);

        return $result;
    }

}