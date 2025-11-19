<?php

namespace App\Service\Impl;

use App\Lib\CommonResult;
use App\Lib\ConfigKey;
use App\Lib\ErrorCode;
use App\Lib\Facade\Cache;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Facade\Validator;
use App\Lib\Helper;
use App\Lib\NoSqlKey;
use App\Lib\RStream;
use App\Lib\Scene;
use App\Lib\UserLogBuilder;
use App\Model\Group;
use App\Model\GroupUser;
use App\Model\User;
use App\Repository\Impl\ChatRepository;
use App\Repository\Impl\ConfigRepository;
use App\Repository\Impl\GroupRepository;
use App\Repository\Impl\GroupUserRepository;
use App\Repository\Impl\SessionRepository;
use App\Repository\Impl\SysChatRepository;
use App\Repository\Impl\UserLogRepository;
use App\Repository\Impl\UserRepository;
use Hyperf\DbConnection\Db;
use stdClass;

class GroupUserService
{

    /**
     * 单一用户主动加群, 需检查是否此群是否可主动加入.
     *
     * @param  array  $user
     * @param  array  $input
     *
     * @return \App\Lib\CommonResult
     */
    public function userJoinGroup(array $user, array $input): CommonResult
    {
        $vResult = Validator::validate($input, ['group_code' => 'required|string'], ['group_code.required' => 'group_code参数错误!']);
        if ( ! $vResult->success) {
            return CommonResult::make(ErrorCode::ErrInvalidParam, $vResult->msg);
        }

        /** @var GroupRepository $groupRepo */
        $groupRepo = Container::get(GroupRepository::class);
        $group     = $groupRepo->getByCode($user['site_bid'], $input['group_code']);
        if (empty($group)) {
            return CommonResult::make(ErrorCode::ErrGroupNotExists, '未找到该群组');
        }

        if (strtoupper($user['site_bid']) !== strtoupper($group['site_bid'])) {
            Log::info(sprintf('用户加群site_bid比对错误! 用户: %s, 群: %s'.json_encode($user, 256), json_encode($group, 256)), 'join_group_site_bid_err');

            return CommonResult::make(ErrorCode::ErrGroupNotExists, '未找到该群组');
        }

        if ((int)$group['is_dismiss'] === Group::DismissYes) {
            return CommonResult::make(ErrorCode::ErrGroupNotExists, '未找到该群组');
        }

        //检查是否可主动
        if ((int)$group['open_join'] === Group::OpenJoinNo) {
            return CommonResult::make(ErrorCode::ErrGroupNotOpenForJoin, '本群尚未开放主动加入');
        }

        //检查用户加入资格.
        if ((int)$user['user_level'] < $group['join_user_level']) {
            return CommonResult::make(ErrorCode::ErrJoinUserUnqualified, '您的资格不符，无法加群');
        }

        /** @var GroupUserRepository $groupUserRepo */
        $groupUserRepo = Container::get(GroupUserRepository::class);
        //检查群人数是否已满
        if ($group['user_limit'] < $groupUserRepo->countGroupUser($group['id']) + 1) {
            return CommonResult::make(ErrorCode::ErrGroupUserLimitExceed, '该群已满员');
        }

        //检查用户是否已加入过
        $existsUser = $groupUserRepo->getGroupUser($user['id'], $group['id']);
        if ( ! empty($existsUser)) {
            return CommonResult::make(ErrorCode::ErrUserHasJoinedGroup, '您已加入群组');
        }

        Db::beginTransaction();
        try {
            if ( ! $groupUserRepo->addGroupUsers([$user], $group['id'], GroupUser::RoleUser)) {
                throw new \PDOException('加入失败');
            }

            /** @var \App\Lib\UserLogBuilder $userLogger */
            $userLogger = Container::make(UserLogBuilder::class, ['siteBid' => $user['site_bid']]);
            $userLogger->setLogType(UserLogBuilder::TypeJoinGroup)->setParam($user['id'], $user['ext_member_id'], $user['ext_username'])->sceneInApp()->bySelf()->withRemark(
                sprintf('加入群组，群号: %s', $group['code']),
            );

            /** @var UserLogRepository $userLogRepo */
            $userLogRepo = Container::get(UserLogRepository::class);
            $userLogRepo->addWithBuilder($userLogger);

            Db::commit();
        } catch (\Throwable $e) {
            Log::error(sprintf('用户加入群组异常! 用户ID: %s, 群ID: %s, 讯息: %s', $user['id'], $group['id'], Helper::getExpDetails($e)), 'user_join_group_err');
            Db::rollback();

            return CommonResult::make(ErrorCode::ErrJoinGroupFailed, '加入失败');
        }

        Cache::del(NoSqlKey::allGroup($group['site_bid']));
        $userCount = $groupUserRepo->countGroupUser($group['id']);
        RStream::push(RStream::TypeNotifyGroupState, ['group_id' => $group['id'], 'user_count' => $userCount]);//通知更新群内状态.

        return CommonResult::success('恭喜！已添加群', ['group_id' => $group['id']]);
    }

    /**
     * 单一用户主动退群
     *
     * @param  array  $user
     * @param  array  $input
     *
     * @return \App\Lib\CommonResult
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function userQuitGroup(array $user, array $input): CommonResult
    {
        $vResult = Validator::validate($input, ['group_code' => 'required|string|min:1'], ['group_code.required' => '参数错误!']);
        if ( ! $vResult->success) {
            return CommonResult::make(ErrorCode::ErrInvalidParam, $vResult->msg);
        }

        /** @var GroupRepository $groupRepo */
        $groupRepo = Container::get(GroupRepository::class);
        $group     = $groupRepo->getByCode($user['site_bid'], $input['group_code'], ['id', 'title', 'code', 'site_bid']);
        if (empty($group)) {
            return CommonResult::make(ErrorCode::ErrGroupNotExists, '未找到该群组');
        }

        /** @var GroupUserRepository $groupUserRepo */
        $groupUserRepo = Container::get(GroupUserRepository::class);

        //检查用户是否属于该群
        $groupUser = $groupUserRepo->getGroupUser($user['id'], $group['id']);
        if (empty($groupUser)) {
            return CommonResult::make(ErrorCode::ErrNotBelongsToGroup, '用户不属于该群组');
        }

        if ((int)$groupUser['role_type'] === GroupUser::RoleOwner) {
            return CommonResult::make(ErrorCode::ErrOwnerCannotQuitGroup, '群主不可主动退群');
        }

        Db::beginTransaction();
        try {
            if ( ! $groupUserRepo->delGroupUser($groupUser['id'])) {
                return CommonResult::error('删除group.user失败');
            }

            /** @var \App\Lib\UserLogBuilder $userLogger */
            $userLogger = Container::make(UserLogBuilder::class, ['siteBid' => $user['site_bid']]);
            $userLogger->setLogType(UserLogBuilder::TypeQuitGroup)->setParam($user['id'], $user['ext_member_id'], $user['ext_username'])->sceneInApp()->bySelf()->withRemark(
                sprintf('退出群组，群号: %s', $group['code']),
            );

            /** @var UserLogRepository $userLogRepo */
            $userLogRepo = Container::get(UserLogRepository::class);
            $userLogRepo->addWithBuilder($userLogger);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error(sprintf('用户退出群组异常! 用户ID: %s, 群ID: %s, 讯息: %s', $user['id'], $group['id'], Helper::getExpDetails($e)), 'user_quit_group_err');

            return CommonResult::make(ErrorCode::ErrQuitGroupFailed, '退出失败');
        }

        $name = ! empty($user['ext_username']) ? Helper::maskUsername($user['ext_username']) : $user['id'];

        Cache::del(NoSqlKey::allGroup($group['site_bid']));
        $userCount = $groupUserRepo->countGroupUser($group['id']);

        return CommonResult::success('已退出', ['group_id' => $groupUser['group_id'], 'user_id' => $groupUser['user_id'], 'name' => $name, 'user_count' => $userCount]);
    }

    /**
     * 取用户所属群组
     *
     * @param  int    $userId
     * @param  array  $getParams
     *
     * @return array
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function getUserGroupList(int $userId, array $getParams = []): array
    {
        $page     = isset($getParams['page']) ? (int)$getParams['page'] : 1;
        $pageSize = isset($getParams['count']) ? (int)$getParams['count'] : 2000;
        /** @var GroupUserRepository $groupUserRepo */
        $groupUserRepo = Container::get(GroupUserRepository::class);
        /** @var \App\Lib\Paginator $pagination */
        [$userGroups, $pagination] = $groupUserRepo->getUserGroups($userId, $page, $pageSize);
        $userGroupIds    = array_column($userGroups, 'id');
        $unreadCountList = $groupUserRepo->getUserGroupsUnreadCount($userId);
        $unreadCountMap  = array_column($unreadCountList, 'unread_count', 'group_id');

        /** @var ChatRepository $chatRepo */
        $chatRepo         = Container::get(ChatRepository::class);
        $lastChatOfGroups = $chatRepo->getLastChatOfGroups($userId, $userGroupIds);
        $lastChatMap      = array_column($lastChatOfGroups, null, 'group_id');

        /** @var ConfigRepository $configRepo */
        $configRepo = Container::get(ConfigRepository::class);
        $cdnUrl     = $configRepo->getByKey(ConfigKey::CdnUrl);
        $infoList   = [];
        /** @var ConfigRepository $configRepo */
        $configRepo   = Container::get(ConfigRepository::class);
        $defaultCover = $configRepo->getByKey(ConfigKey::DefaultGroupCoverMy);
        foreach ($userGroups as $groupInfo) {
            $unreadCount = $unreadCountMap[$groupInfo->id] ?? -1;
            if ($unreadCount === -1) {
                continue;
            }
            $groupInfo->unread_count  = (int)$unreadCount;
            $groupInfo->pin_time      = (int)$groupInfo->pin_time;
            $groupInfo->is_pin        = $groupInfo->pin_time > 0 ? 1 : 0;
            $groupInfo->cover_pic_url = ! empty($groupInfo->my_group_cover_pic_url) ? $cdnUrl.$groupInfo->my_group_cover_pic_url : $cdnUrl.$defaultCover;

            $lastChat             = $lastChatMap[$groupInfo->id] ?? null;
            $groupInfo->last_chat = new stdClass();
            if ( ! empty($lastChat)) {
                $groupInfo->last_chat->type        = (int)$lastChat->type;
                $groupInfo->last_chat->content     = $lastChat->content;
                $groupInfo->last_chat->time        = date('m-d H:i', $lastChat->create_time);
                $groupInfo->last_chat->create_time = (int)$lastChat->create_time;
            }
            $infoList[] = $groupInfo;
        }

        usort($infoList, function ($l, $r) {
            if ($l->pin_time !== $r->pin_time) {
                return $l->pin_time < $r->pin_time ? 1 : -1; // 大到小
            }

            //最新讯息时间BEGIN
            $aTime = $l->last_chat->create_time ?? null;
            $bTime = $r->last_chat->create_time ?? null;

            if ($aTime && $bTime) {
                return $aTime < $bTime ? 1 : -1;
            }

            if ($aTime) {
                return -1;
            }
            if ($bTime) {
                return 1;
            }

            return 0;
        });

        return ['groups' => $infoList, 'pagination' => $pagination->get()];
    }

    /**
     * 将群置顶.
     *
     * @param  array  $user
     * @param  array  $param
     *
     * @return \App\Lib\CommonResult
     */
    public function pinGroup(array $user, array $param): CommonResult
    {
        $vResult = Validator::validate($param, ['group_id' => 'required'], ['group_id.required' => '缺少group_code参数']);
        if ( ! $vResult->success) {
            return CommonResult::make(ErrorCode::ErrInvalidParam, $vResult->msg);
        }
        $param['group_id'] = (int)$param['group_id'];

        /** @var GroupRepository $groupRepo */
        $groupRepo = Container::get(GroupRepository::class);
        $group     = $groupRepo->getById($param['group_id']);
        if ( ! $group) {
            return CommonResult::make(ErrorCode::ErrGroupNotExists, '该群不存在');
        }

        /** @var GroupUserRepository $groupUserRepo */
        $groupUserRepo = Container::get(GroupUserRepository::class);
        $groupUser     = $groupUserRepo->getGroupUser($user['id'], $group['id']);
        if (empty($groupUser)) {
            return CommonResult::make(ErrorCode::ErrNotBelongsToGroup, '用户不属于该群组');
        }

        $ok = $groupUserRepo->updatePinGroup($groupUser['id']);
        if ( ! $ok) {
            return CommonResult::error('置顶失败');
        }

        return CommonResult::success('置顶成功');
    }

    /**
     * 将群置顶.
     *
     * @param  array  $user
     * @param  array  $param
     *
     * @return \App\Lib\CommonResult
     */
    public function unpinGroup(array $user, array $param): CommonResult
    {
        $vResult = Validator::validate($param, ['group_id' => 'required|min:1'], ['group_id.required' => '参数错误', 'group_id.min' => '群ID错误']);
        if ( ! $vResult->success) {
            return CommonResult::make(ErrorCode::ErrInvalidParam, $vResult->msg);
        }

        $param['group_id'] = (int)$param['group_id'];
        /** @var GroupRepository $groupRepo */
        $groupRepo = Container::get(GroupRepository::class);
        $group     = $groupRepo->getById($param['group_id']);
        if ( ! $group) {
            return CommonResult::make(ErrorCode::ErrGroupNotExists, '群组不存在');
        }

        /** @var GroupUserRepository $groupUserRepo */
        $groupUserRepo = Container::get(GroupUserRepository::class);
        $groupUser     = $groupUserRepo->getGroupUser($user['id'], $group['id']);
        if (empty($groupUser)) {
            return CommonResult::make(ErrorCode::ErrNotBelongsToGroup, '用户不属于该群组');
        }

        $ok = $groupUserRepo->updatePinGroup($groupUser['id'], false);

        if ( ! $ok) {
            return CommonResult::error('取消置顶失败');
        }

        return CommonResult::success('取消置顶成功');
    }

    /**
     * 用户进群处理
     *
     * @param  int    $fd
     * @param  array  $user
     * @param  array  $group
     *
     * @return \App\Lib\CommonResult
     */
    public function enterGroup(int $fd, array $user, array $group): CommonResult
    {
        if (empty($user) || empty($group['id'])) {
            return CommonResult::error('进群参数错误');
        }

        if (isset($user['user_level']) && $user['user_level'] < $group['join_user_level']) {
            return CommonResult::error('用户等级不足以进群');
        }

        /** @var SessionRepository $sessionRepo */
        $sessionRepo = Container::get(SessionRepository::class);
        $sessionRepo->rSetScene($fd, Scene::InGroup, (int)$group['id']);

        /** @var GroupUserRepository $groupUserRepo */
        $groupUserRepo = Container::get(GroupUserRepository::class);
        $groupUser     = $groupUserRepo->getGroupUser($user['id'], $group['id']);
        if (empty($groupUser)) {
            return CommonResult::make(ErrorCode::ErrNotBelongsToGroup, '用户不属于群组');
        }

        $onlineInfo = ['fd' => $fd, 'is_ban' => (int)$groupUser['is_ban'], 'role_type' => (int)$groupUser['role_type']];
        $groupUserRepo->rSetGroupOnlineUser($group['id'], $user['id'], $onlineInfo);

        return CommonResult::success('ok', ['group_user' => $groupUser]);
    }

    /**
     * 组建给client的资讯.
     *
     * @param  array  $session
     * @param  array  $group
     * @param  array  $groupUser
     *
     * @return array
     */
    public function buildEnterGroupInfo(array $session, array $group, array $groupUser): array
    {
        /** @var ChatService $chatService */
        $chatService   = Container::get(ChatService::class);
        $session['id'] = (int)$session['id'];
        /** @var UserRepository $userRepo */
        $userRepo = Container::get(UserRepository::class);
        $user     = $userRepo->getOne($session['id'], ['is_global_ban']);
        $chatList = $chatService->getEnterGroupInitInfo($group, $groupUser);
        foreach ($chatList as $idx => $chat) {
            $chatList[$idx]['is_client'] = (int)$chat['user']['id'] === $session['id'] ? 1 : 0;
        }

        /** @var GroupRepository $groupRepo */
        $groupRepo = Container::get(GroupRepository::class);
        $groupInfo = $groupRepo->getGroupInfo($group['id']);

        $isPin                        = $groupUser['pin_time'] > 0 ? 1 : 0;
        $info['group']                = [
            'id'               => $groupInfo['id'],
            'code'             => $groupInfo['code'],
            'title'            => $groupInfo['title'],
            'user_count'       => $groupInfo['user_count'],
            'is_pin'           => $isPin,
            'speak_user_level' => $groupInfo['speak_user_level'],
        ];
        $info['chat_list']            = $chatList;
        $info['user_level']           = (int)$session['user_level'];
        $info['user_level_speakable'] = $session['user_level'] >= $group['speak_user_level'] ? 1 : 0;
        $info['user_is_ban']          = (int)$groupUser['is_ban'] === GroupUser::BanYes ? 1 : 0;
        $info['user_is_global_ban']   = (int)$user['is_global_ban'] === User::IsGlobalBanYes ? 1 : 0;
        $info['last_read_chat_id']    = (int)$groupUser['last_read_chat_id'];

        $info['pin_chat'] = new \stdClass();
        if ( ! empty($group['pin_chat_id'])) {
            $info['pin_chat'] = (object)$chatService->getPinChat($group['pin_chat_id']);
        }

        $info['online_user_count'] = 0;
        $info['user_role']         = (int)$groupUser['role_type'];
        if ((int)$groupUser['role_type'] !== GroupUser::RoleUser) {
            /** @var GroupUserRepository $groupUserRepo */
            $groupUserRepo             = Container::get(GroupUserRepository::class);
            $onlineUserCount           = count($groupUserRepo->rGetGroupOnlineUsers($group['id']));
            $info['online_user_count'] = $onlineUserCount;
        }

        return $info;
    }

    /**
     * 彩票端取用户未读数.
     *
     * @param  array  $param
     *
     * @return \App\Lib\CommonResult
     */
    public function getUnreadCountByExtInfo(array $param): CommonResult
    {
        if (empty($param['member_id']) || empty($param['site_bid'])) {
            return CommonResult::invalidParam('参数错误');
        }

        /** @var UserRepository $userRepo */
        $userRepo = Container::get(UserRepository::class);
        $user     = $userRepo->extQueryUser($param['site_bid'], $param['member_id']);
        if (empty($user)) {
            return CommonResult::success('ok', ['count' => 0]);
        }

        /** @var GroupUserRepository $groupUserRepo */
        $groupUserRepo = Container::get(GroupUserRepository::class);

        /** @var SysChatRepository $sysChatRepo */
        $sysChatRepo = Container::get(SysChatRepository::class);

        $unreadKey = NoSqlKey::extUnread($user['site_bid'], $user['ext_member_id']);
        if (Cache::has($unreadKey)) {
            $unreadCount = Cache::get($unreadKey);

            return CommonResult::success('ok', ['count' => $unreadCount]);
        }

        try {
            $groupUnread = $groupUserRepo->getExtUnreadCount($user['id']);
            $sysUnread   = $sysChatRepo->getUnreadCount($user['id'], $user['sys_chat_last_read_id'], $user['site_bid']);
            if ($sysUnread > 0) {
                $sysUnread = 1;
            }
            $sumUnread = $groupUnread + $sysUnread;
            Cache::set($unreadKey, $sumUnread, Cache::randTTL());
        } catch (\Throwable $e) {
            Log::error(sprintf('彩票查询未读异常! 参数: %s, 讯息: %s', json_encode($param, 256), Helper::getExpDetails($e)), 'getUnreadCountByExtInfo_err');
            $sumUnread = 0;
        }

        return CommonResult::success('ok', ['count' => $sumUnread]);
    }

    /**
     * 更新用户最后已读讯息ID
     *
     * @param  array  $session
     * @param  array  $param
     *
     * @return \App\Lib\CommonResult
     */
    public function setLastRead(array $session, array $param): CommonResult
    {
        if (empty($param['chat_id']) || empty($param['group_id'])) {
            return CommonResult::invalidParam('参数错误');
        }

        /** @var GroupUserRepository $groupUserRepo */
        $groupUserRepo = Container::get(GroupUserRepository::class);
        $groupUser     = $groupUserRepo->getGroupUser($session['id'], $param['group_id']);
        if ( ! $groupUser) {
            return CommonResult::make(ErrorCode::ErrInvalidOperate, '群组错误');
        }

        if ((int)$groupUser['last_read_chat_id'] === (int)$param['chat_id']) {
            return CommonResult::success('无须更新');
        }

        $onlineUser = $groupUserRepo->rGetGroupOnlineUser($groupUser['group_id'], $session['id']);
        if (empty($onlineUser)) {
            return CommonResult::make(ErrorCode::ErrWrongOperateScene, '用户不在群内');
        }

        /** @var ChatRepository $chatRepo */
        $chatRepo = Container::get(ChatRepository::class);
        $chat     = $chatRepo->getById($param['chat_id']);
        if (empty($chat)) {
            return CommonResult::make(ErrorCode::ErrInvalidOperate, '未找到聊天讯息');
        }

        if ((int)$chat['group_id'] !== (int)$groupUser['group_id']) {
            return CommonResult::make(ErrorCode::ErrInvalidOperate, '消息与群组不匹配');
        }

        $ok = $groupUserRepo->updateUserLastRead($groupUser['id'], $chat['id']);
        if ( ! $ok) {
            return CommonResult::make(ErrorCode::ErrUpdateLastReadFailed, '更新失败');
        }

        Cache::del(NoSqlKey::extUnread($session['site_bid'], $session['ext_member_id']));

        return CommonResult::success('操作成功');
    }

    /**
     * @param  array  $data
     *
     * @return \App\Lib\CommonResult
     */
    public function queueUserQuitGroup(array $data): CommonResult
    {
        RStream::push(RStream::TypeNotifyGroupState, ['group_id' => $data['group_id'], 'user_count' => $data['user_count']]);//通知更新群内状态.
        $ok = RStream::push(RStream::TypeUserQuitGroup, $data);
        if ( ! $ok) {
            Log::error('推送用户退群通知失败!', 'queue_user_quit_group_err');
        }

        return CommonResult::success('已退出');
    }

    /**
     * 检查是否离开一般群并调整在线资讯
     *
     * @param  array   $session
     * @param  string  $toScene
     *
     * @return void
     */
    public function changeScene(array $session, string $toScene): void
    {
        /** @var GroupUserRepository $groupUserRepo */
        $groupUserRepo = Container::get(GroupUserRepository::class);

        $scene = $session['scene'] ?? [];
        if ($scene['name'] === Scene::InGroup) {
            $groupId = Scene::fetchGroupId($session);
            if ($groupId > 0) {
                $groupUserRepo->rDelOnlineUser($groupId, $session['id']);
            }
        }
    }

}