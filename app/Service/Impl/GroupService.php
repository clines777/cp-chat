<?php

namespace App\Service\Impl;

use App\Lib\CommonResult;
use App\Lib\ConfigKey;
use App\Lib\ErrorCode;
use App\Lib\Facade\Cache;
use App\Lib\Facade\Container;
use App\Lib\Facade\Validator;
use App\Lib\Helper;
use App\Lib\NoSqlKey;
use App\Lib\RStream;
use App\Model\Group;
use App\Model\GroupUser;
use App\Repository\Impl\ConfigRepository;
use App\Repository\Impl\GroupRepository;
use App\Repository\Impl\GroupUserRepository;

class GroupService
{

    protected GroupRepository $groupRepo;

    public function __construct(GroupRepository $groupRepository)
    {
        $this->groupRepo = $groupRepository;
    }

    /**
     * @param  array  $user  ['id'(user_id), 'site_bid'(站点ID), 'user_level'(用户等级)]
     * @param  array  $param
     *
     * @return array
     */
    public function getLobbyInfo(array $user, array $param = []): array
    {
        $siteBid  = $user['site_bid'];
        $page     = isset($param['page']) ? (int)$param['page'] : 1;
        $pageSize = isset($param['count']) ? (int)$param['count'] : 2000;//先固定, 之后再做分页

        $info = [];
        [$groups, $pagination] = $this->groupRepo->getLobbyGroups($siteBid, $user, $page, $pageSize, $param);
        if ( ! empty($groups)) {
            /** @var ConfigRepository $configRepo */
            $configRepo = Container::get(ConfigRepository::class);
            $cdnUrl     = $configRepo->getByKey(ConfigKey::CdnUrl);
            /** @var ConfigRepository $configRepo */
            $configRepo   = Container::get(ConfigRepository::class);
            $defaultCover = $configRepo->getByKey(ConfigKey::DefaultGroupCoverLobby);
            foreach ($groups as $g) {
                $g->cover_pic_url = ! empty($g->lobby_cover_pic_url) ? $cdnUrl.$g->lobby_cover_pic_url : $cdnUrl.$defaultCover;
                $g->is_joined     = (int)$g->join_state === GroupRepository::LobbyGroupFilterJoinedOnly ? 1 : 0;
                unset($g->lobby_cover_pic_url);
            }
        }

        $info['groups']     = $groups;
        $info['pagination'] = $pagination->get();

        return $info;
    }

    /**
     * 取群聊信息页展示资讯.
     *
     * @param  array  $param
     *
     * @return \App\Lib\CommonResult
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function getGroupBasicInfo(array $param): CommonResult
    {
        $vResult = Validator::validate($param, ['group_id' => 'required|integer']);
        if ( ! $vResult->success) {
            return CommonResult::invalidParam("参数验证错误:".$vResult->msg);
        }

        $group = $this->groupRepo->getById($param['group_id']);
        if (empty($group)) {
            return CommonResult::error('该群组不存在');
        }

        /** @var GroupUserRepository $groupUserRepo */
        $groupUserRepo = Container::get(GroupUserRepository::class);
        $userCount     = $groupUserRepo->countGroupUser($group['id']);

        $info               = [];
        $info['group_id']   = (int)$group['id'];
        $info['code']       = $group['code'];
        $info['title']      = $group['title'];
        $info['bulletin']   = $group['bulletin'];
        $info['user_count'] = $userCount;

        return CommonResult::success('ok', $info);
    }

    /**
     * 群内修改群信息.
     *
     * @param  array  $user
     * @param  array  $param
     *
     * @return \App\Lib\CommonResult
     */
    public function updateGroup(array $user, array $param): CommonResult
    {
        $vResult = Validator::validate($param, ['group_id' => 'required|integer']);
        if ( ! $vResult->success) {
            return CommonResult::invalidParam("参数验证错误:".$vResult->msg);
        }

        if ( ! isset($param['title']) && ! isset($param['bulletin'])) {
            return CommonResult::invalidParam("参数验证错误:title或bulletin至少填一个");
        }

        /**
         * 检查群.
         */
        /** @var GroupRepository $groupRepo */
        $groupRepo = Container::get(GroupRepository::class);
        $group     = $groupRepo->getById($param['group_id']);
        if (empty($group)) {
            return CommonResult::make(ErrorCode::ErrGroupNotExists, '查无此群');
        }

        /**
         * 检查是否属于该群.
         */
        /** @var GroupUserRepository $groupUserRepo */
        $groupUserRepo = Container::get(GroupUserRepository::class);
        $groupUser     = $groupUserRepo->getGroupUser($user['id'], $group['id']);
        if (empty($groupUser)) {
            return CommonResult::make(ErrorCode::ErrNotBelongsToGroup, '用户不属于该群组');
        }

        /**
         * 检查群用户身分
         */
        if ((int)$groupUser['role_type'] === GroupUser::RoleUser) {
            return CommonResult::make(ErrorCode::ErrInvalidOperate, '当前用户非管理者');
        }

        $updateParam = [];
        if (isset($param['title'])) {//null表示不设置.
            if (mb_strlen($param['title']) > Group::TitleMaxLen) {
                return CommonResult::invalidParam('群标题最多'.Group::TitleMaxLen.'字');
            }

            $updateParam['title'] = Helper::secureStr($param['title']);
        }

        if (isset($param['bulletin'])) {//null表示不设置.
            if (mb_strlen($param['bulletin']) > Group::BulletinMaxLen) {
                return CommonResult::invalidParam('群公告最多'.Group::BulletinMaxLen.'字');
            }

            $updateParam['bulletin'] = Helper::secureStr($param['bulletin']);
        }

        $ok = true;
        if ( ! empty($updateParam)) {
            $ok = $groupRepo->update($group['id'], $updateParam);
        }
        if ( ! $ok) {
            return CommonResult::error('操作失败');
        }

        Cache::del(NoSqlKey::allGroup($group['site_bid']));

        $newGroup  = $groupRepo->getById($param['group_id']);
        $groupInfo = ['id' => $newGroup['id'], 'title' => $newGroup['title'], 'bulletin' => $newGroup['bulletin']];

        if ( ! empty($updateParam['title']) && $group['title'] !== $newGroup['title']) {
            $notifyState['title']    = $newGroup['title'];
            $notifyState['group_id'] = $newGroup['id'];
            RStream::push(RStream::TypeNotifyGroupState, $notifyState);
        }

        return CommonResult::success('ok', $groupInfo);
    }

}