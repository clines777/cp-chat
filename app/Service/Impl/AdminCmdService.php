<?php

namespace App\Service\Impl;

use App\Lib\CommonResult;
use App\Lib\ErrorCode;
use App\Lib\Facade\Cache;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Helper;
use App\Lib\NoSqlKey;
use App\Lib\RStream;
use App\Lib\UserLogBuilder;
use App\Model\GroupUser;
use App\Repository\Impl\GroupRepository;
use App\Repository\Impl\GroupUserRepository;
use App\Repository\Impl\UserLogRepository;
use App\Repository\Impl\UserRepository;
use Hyperf\DbConnection\Db;

//管理员对用户操作.
class AdminCmdService
{

    /**
     * @var \App\Repository\Impl\GroupUserRepository
     */
    protected GroupUserRepository $groupUserRepo;

    public function __construct(UserRepository $userRepo, GroupUserRepository $groupUserRepo)
    {
        $this->groupUserRepo = $groupUserRepo;
    }

    /**
     * 禁言处理.
     *
     * @param  array  $groupUser
     *
     * @return CommonResult
     */
    public function queueBanUser(array $groupUser): CommonResult
    {
        $streamData = ['group_id' => $groupUser['group_id'], 'user_id' => $groupUser['user_id']];
        $ok         = RStream::push(RStream::TypeBanUser, $streamData);
        if ( ! $ok) {
            Log::error('用户禁言, 消息推送进管道失败', 'queue_ban_user_err');

            return CommonResult::error('操作失败');
        }

        return CommonResult::success('操作成功');
    }

    /**
     * @param  array  $groupUser
     *
     * @return \App\Lib\CommonResult
     */
    public function queueUnbanUser(array $groupUser): CommonResult
    {
        $ok = RStream::push(RStream::TypeUnbanUser, ['group_id' => $groupUser['group_id'], 'user_id' => $groupUser['user_id']]);

        if ( ! $ok) {
            Log::error('用户踢群, 消息推送进管道失败', 'queue_unban_user_err');

            return CommonResult::error('操作失败');
        }

        return CommonResult::success('操作成功');
    }

    /**
     * 用户踢群.
     *
     * @param  array  $groupUsers
     *
     * @return CommonResult
     */
    public function queueKickUser(array $groupUsers): CommonResult
    {
        foreach ($groupUsers as $groupUser) {
            if (empty($groupUser->group_id) || empty($groupUser->user_id)) {
                Log::error('参数错误, 缺少group_id或user_id', 'queue_kick_user_err');
                continue;
            }
            try {
                $ok = RStream::push(RStream::TypeKickUser, ['group_id' => $groupUser->group_id, 'user_id' => $groupUser->user_id]);
                if ( ! $ok) {
                    throw new \RuntimeException(sprintf('用户踢群, 消息推送进管道失败, 参数: %s', json_encode($groupUser, JSON_UNESCAPED_UNICODE)));
                }
            } catch (\Throwable $e) {
                Log::error(Helper::getExpDetails($e), 'queue_kick_user_err');
            }
        }

        return CommonResult::success('操作成功');
    }

    /**
     * 将用户全域禁言.
     *
     * @param  int  $userId
     *
     * @return \App\Lib\CommonResult
     */
    public function globalBanUser(int $userId, int $adminId): CommonResult
    {
        /** @var UserRepository $userRepo */
        $userRepo = Container::get(UserRepository::class);
        $user     = $userRepo->getOne($userId);
        if (empty($user)) {
            return CommonResult::error('查不到该用户');
        }

        $ok = $userRepo->setGlobalBanned($user);
        if ( ! $ok) {
            return CommonResult::error('操作失败');
        }

        /** @var UserLogBuilder $builder */
        $builder = Container::make(UserLogBuilder::class, ['siteBid' => $user['site_bid']]);
        $builder->setLogType(UserLogBuilder::TypeGlobalBan)->setParam($user['id'], $user['ext_member_id'], $user['ext_username'])->byCpAdmin($adminId);

        /** @var UserLogRepository $userLogRepo */
        $userLogRepo = Container::get(UserLogRepository::class);
        $userLogRepo->addWithBuilder($builder);

        return CommonResult::success('ok', ['user_id' => $user['id']]);
    }

    /**
     * 全域禁言通知入管道
     *
     * @param  array  $info
     *
     * @return \App\Lib\CommonResult
     */
    public function queueGlobalBan(array $info): CommonResult
    {
        $ok = RStream::push(RStream::TypeGlobalBan, $info);
        if ( ! $ok) {
            Log::error('用户全域禁言, 消息推送进管道失败', 'queue_global_ban_err');

            return CommonResult::error('操作失败');
        }

        return CommonResult::success('操作成功');
    }

    /**
     * 解除用户全域禁言.
     *
     * @param  int  $userId
     * @param  int  $adminId
     *
     * @return \App\Lib\CommonResult
     */
    public function globalUnbanUser(int $userId, int $adminId): CommonResult
    {
        /** @var UserRepository $userRepo */
        $userRepo = Container::get(UserRepository::class);
        $user     = $userRepo->getOne($userId);
        if (empty($user)) {
            return CommonResult::error('查不到该用户');
        }

        $ok = $userRepo->setGlobalUnbanned($user);
        if ( ! $ok) {
            return CommonResult::error('操作失败');
        }

        /** @var UserLogBuilder $builder */
        $builder = Container::make(UserLogBuilder::class, ['siteBid' => $user['site_bid']]);
        $builder->setLogType(UserLogBuilder::TypeGlobalUnban)->setParam($user['id'], $user['ext_member_id'], $user['ext_username'])->byCpAdmin($adminId);

        /** @var UserLogRepository $userLogRepo */
        $userLogRepo = Container::get(UserLogRepository::class);
        $userLogRepo->addWithBuilder($builder);

        return CommonResult::success('操作成功', ['user_id' => $user['id']]);
    }

    /**
     * 全域解禁通知入管道
     *
     * @param  array  $info
     *
     * @return \App\Lib\CommonResult
     */
    public function queueGlobalUnban(array $info): CommonResult
    {
        $ok = RStream::push(RStream::TypeGlobalUnban, $info);
        if ( ! $ok) {
            Log::error('用户全域解禁, 消息推送进管道失败', 'queue_global_unban_err');

            return CommonResult::error('操作失败');
        }

        return CommonResult::success('操作成功');
    }

    /**
     * 彩票后台踢用户出群.
     *
     * @param  int  $userId
     * @param  int  $groupId
     * @param  int  $adminId
     *
     * @return \App\Lib\CommonResult
     */
    public function kickUser(int $userId, int $groupId, int $adminId): CommonResult
    {
        /** @var UserRepository $userRepo */
        $userRepo = Container::get(UserRepository::class);
        $user     = $userRepo->getOne($userId, ['id', 'ext_member_id', 'ext_username']);
        if (empty($user)) {
            Log::error('用户踢群, 查无此用户', 'kick_user_err');

            return CommonResult::error('查无此用户');
        }

        /** @var GroupRepository $groupRepo */
        $groupRepo = Container::get(GroupRepository::class);
        $group     = $groupRepo->getById($groupId, ['id', 'code', 'site_bid']);
        if (empty($group)) {
            return CommonResult::error('群ID错误, 查无此群');
        }

        $groupUser = $this->groupUserRepo->getGroupUser($user['id'], $groupId);
        if (empty($groupUser)) {
            Log::error('用户踢群, 用户不属于该群组', 'kick_user_err');

            return CommonResult::error('用户不属于该群组');
        }

        $ok = $this->groupUserRepo->delGroupUser($groupUser['id']);
        if ( ! $ok) {
            Log::error('删除group_user失败', 'kick_user_err');

            return CommonResult::error('操作失败');
        }

        /** @var UserLogBuilder $userLogger */
        $userLogger = Container::make(UserLogBuilder::class, ['siteBid' => $group['site_bid']]);
        $userLogger->setParam($user['id'], $user['ext_member_id'], $user['ext_username'])->byCpAdmin($adminId)->setLogType(UserLogBuilder::TypeKickGroup)->withRemark(
            sprintf('踢出群组，群号: %s', $group['code']),
        );

        /** @var UserLogRepository $userLogRepo */
        $userLogRepo = Container::get(UserLogRepository::class);
        $userLogRepo->addWithBuilder($userLogger);

        $userCount = $this->groupUserRepo->countGroupUser($group['id']);
        RStream::push(RStream::TypeNotifyGroupState, ['group_id' => $group['id'], 'user_count' => $userCount]);

        return CommonResult::success('ok', $groupUser);
    }

    /**
     * 彩票后台将用户禁言
     *
     * @param  int  $userId
     * @param  int  $groupId
     * @param  int  $adminId
     *
     * @return \App\Lib\CommonResult
     */
    public function banUser(int $userId, int $groupId, int $adminId): CommonResult
    {
        /** @var UserRepository $userRepo */
        $userRepo = Container::get(UserRepository::class);
        $user     = $userRepo->getOne($userId, ['id', 'ext_member_id', 'ext_username']);
        if (empty($user)) {
            return CommonResult::error('群聊端未查到该用户');
        }

        /** @var GroupRepository $groupRepo */
        $groupRepo = Container::get(GroupRepository::class);
        $group     = $groupRepo->getById($groupId, ['id', 'code', 'site_bid']);
        if (empty($group)) {
            return CommonResult::error('群ID错误, 查无此群');
        }

        $groupUser = $this->groupUserRepo->getGroupUser($user['id'], $groupId);
        if (empty($groupUser)) {
            Log::error('用户禁言, 群用户不存在', 'ban_user_err');

            return CommonResult::error('群用户不存在');
        }

        if ((int)$groupUser['role_type'] !== GroupUser::RoleUser) {
            Log::error('用户禁言, 该对象非一般用户', 'ban_user_err');

            return CommonResult::error('禁言对象非一般用户');
        }

        $banParam = ['is_ban' => GroupUser::BanYes];
        if ((int)$groupUser['is_ban'] !== GroupUser::BanYes) {
            $ok = $this->groupUserRepo->updateGroupUserById($groupUser['id'], $banParam);
            if ( ! $ok) {
                Log::error('用户禁言, 修改禁言状态失败!', 'ban_user_err');

                return CommonResult::error('用户禁言, 修改禁言状态失败!');
            }
        }

        /** @var UserLogBuilder $userLogger */
        $userLogger = Container::make(UserLogBuilder::class, ['siteBid' => $group['site_bid']]);
        $userLogger->setParam($user['id'], $user['ext_member_id'], $user['ext_username'])->byCpAdmin($adminId)->setLogType(UserLogBuilder::TypeGroupBan)->withRemark(
            sprintf('用户禁言，群号: %s', $group['code']),
        );

        /** @var UserLogRepository $userLogRepo */
        $userLogRepo = Container::get(UserLogRepository::class);
        $userLogRepo->addWithBuilder($userLogger);

        return CommonResult::success('ok', $groupUser);
    }

    /**
     * 彩票后台将用户禁言
     *
     * @param  int  $userId
     * @param  int  $groupId
     * @param  int  $adminId
     *
     * @return \App\Lib\CommonResult
     */
    public function unbanUser(int $userId, int $groupId, int $adminId): CommonResult
    {
        /** @var UserRepository $userRepo */
        $userRepo = Container::get(UserRepository::class);
        $user     = $userRepo->getOne($userId, ['id', 'ext_member_id', 'ext_username']);
        if (empty($user)) {
            Log::error('用户解禁, 未找到该用户', 'unban_user_err');

            return CommonResult::error('未找到该用户');
        }

        /** @var GroupRepository $groupRepo */
        $groupRepo = Container::get(GroupRepository::class);
        $group     = $groupRepo->getById($groupId, ['id', 'code', 'site_bid']);
        if (empty($group)) {
            return CommonResult::error('群ID错误, 查无此群');
        }

        $groupUser = $this->groupUserRepo->getGroupUser($user['id'], $groupId);
        if (empty($groupUser)) {
            Log::error('用户解禁, 群用户不存在', 'unban_user_err');

            return CommonResult::error('群用户不存在');
        }

        if ((int)$groupUser['role_type'] !== GroupUser::RoleUser) {
            Log::error('用户解禁, 该对象非一般用户', 'unban_user_err');

            return CommonResult::error('该对象非一般用户');
        }

        if ((int)$groupUser['is_ban'] !== GroupUser::BanNo) {
            $ok = $this->groupUserRepo->updateGroupUserById($groupUser['id'], ['is_ban' => GroupUser::BanNo]);
            if ( ! $ok) {
                Log::error('用户解禁, 修改用户禁言状态失败!', 'unban_user_err');

                return CommonResult::error('操作失败');
            }
        }

        /** @var UserLogBuilder $userLogger */
        $userLogger = Container::make(UserLogBuilder::class, ['siteBid' => $group['site_bid']]);
        $userLogger->setParam($user['id'], $user['ext_member_id'], $user['ext_username'])->byCpAdmin($adminId)->setLogType(UserLogBuilder::TypeGroupUnban)->withRemark(
            sprintf('用户解禁，群号: %s', $group['code']),
        );

        /** @var UserLogRepository $userLogRepo */
        $userLogRepo = Container::get(UserLogRepository::class);
        $userLogRepo->addWithBuilder($userLogger);

        return CommonResult::success('ok', $groupUser);
    }

    /**
     * 解散群组.
     *
     * @param  array  $param
     *
     * @return \App\Lib\CommonResult
     */
    public function dismissGroup(array $param): CommonResult
    {
        /** @var GroupRepository $groupRepo */
        $groupRepo = Container::get(GroupRepository::class);
        $group     = $groupRepo->getById($param['group_id']);
        if (empty($group)) {
            Log::error('群组不存在', 'dismiss_group_err');

            return CommonResult::error('群组不存在');
        }

        Db::beginTransaction();
        try {
            $ok = $groupRepo->updateAsDismissed($group['id']);
            if ( ! $ok) {
                throw new \PDOException('dismiss_group, 删除group失败');
            }

            if ($this->groupUserRepo->hasUser($group['id'])) {
                $ok = $this->groupUserRepo->deleteGroupUsers($group['id']);
                if ( ! $ok) {
                    throw new \PDOException('dismiss_group, 删除group_user失败');
                }
            }

            Db::commit();
            Cache::del(NoSqlKey::allGroup($param['site_bid']));

            return CommonResult::success('解散成功');
        } catch (\Throwable $e) {
            Db::rollBack();
            Log::error(sprintf('解散群组异常! 参数: %s, 讯息: %s', json_encode($param, 256), Helper::getExpDetails($e)));

            return CommonResult::error('操作失败');
        }
    }

    /**
     * 推送解散群组操作指令.
     *
     * @param  int  $groupId
     *
     * @return CommonResult
     */
    public function queueDismissGroup(int $groupId): CommonResult
    {
        $streamData = ['group_id' => $groupId];
        $ok         = RStream::push(RStream::TypeDismissGroup, $streamData);
        if ( ! $ok) {
            return CommonResult::error('操作失败');
        }

        return CommonResult::success('操作成功');
    }

    /**
     * 将用户踢出全部群组.
     *
     * @param  int  $userId
     * @param  int  $adminId
     *
     * @return \App\Lib\CommonResult
     */
    public function kickUserAll(int $userId, int $adminId): CommonResult
    {
        if (empty($userId)) {
            return CommonResult::invalidParam('参数错误');
        }

        /** @var UserRepository $userRepo */
        $userRepo = Container::get(UserRepository::class);
        $user     = $userRepo->getOne($userId);
        if (empty($user)) {
            return CommonResult::make(ErrorCode::ErrNotFound, '未找到该用户');
        }

        $notifyGroupIds = [];
        /** @var GroupUserRepository $groupUserRepo */
        $groupUserRepo = Container::get(GroupUserRepository::class);
        $groupUserList = $groupUserRepo->getUserAllGroup($userId, ['id', 'group_id', 'user_id', 'role_type']);
        if (empty($groupUserList)) {
            return CommonResult::success('用户未加入任何群组，毋须操作');
        }

        $filterGroupUsers = [];
        foreach ($groupUserList as $each) {
            if ((int)$each->role_type !== GroupUser::RoleOwner) {
                $filterGroupUsers[] = $each;
                $notifyGroupIds[]   = $each->group_id;
            }
        }

        $groupUserIds = array_column($filterGroupUsers, 'id');
        $ok           = $groupUserRepo->deleteByIds($groupUserIds);
        if ( ! $ok) {
            return CommonResult::error('操作失败');
        }

        /** @var UserLogBuilder $userLogger */
        $userLogger = Container::make(UserLogBuilder::class, ['siteBid' => $user['site_bid']]);
        $userLogger->setParam($user['id'], $user['ext_member_id'], $user['ext_username'])->byCpAdmin($adminId)->setLogType(UserLogBuilder::TypeKickGroupAll)->withRemark(
            '踢出全部群组',
        );

        /** @var UserLogRepository $userLogRepo */
        $userLogRepo = Container::get(UserLogRepository::class);
        $userLogRepo->addWithBuilder($userLogger);

        $this->queueKickUser($filterGroupUsers);
        $msg = sprintf('已将用户踢出%d个群组', count($filterGroupUsers));

        $userCounts   = $groupUserRepo->getUserCountMap($notifyGroupIds);
        $userCountMap = array_column($userCounts, 'user_count', 'group_id');
        foreach ($notifyGroupIds as $eachGroupId) {
            RStream::push(RStream::TypeNotifyGroupState, ['group_id' => $eachGroupId, 'user_count' => $userCountMap[$eachGroupId] ?? 0]);
        }

        return CommonResult::success($msg);
    }

}