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
use App\Lib\SysConst;
use App\Lib\UserLogBuilder;
use App\Model\Group;
use App\Model\GroupQuotaLog;
use App\Model\GroupUser;
use App\Repository\Impl\AdminGroupRepository;
use App\Repository\Impl\AdminUserRepository;
use App\Repository\Impl\ConfigRepository;
use App\Repository\Impl\GroupQuotaLogRepository;
use App\Repository\Impl\GroupRepository;
use App\Repository\Impl\GroupUserRepository;
use App\Repository\Impl\UserLogRepository;
use App\Repository\Impl\UserRepository;
use Hyperf\DbConnection\Db;

class AdminGroupService
{

    protected AdminGroupRepository $repo;

    public function __construct(AdminGroupRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * 取后台群组列表
     *
     * @param  array  $params  后台查询参数.
     *
     * @return \App\Lib\CommonResult
     */
    public function adminGroupIndex(array $params): CommonResult
    {
        $page     = isset($params['page']) ? (int)$params['page'] : 1;
        $pageSize = isset($params['page_size']) ? (int)$params['page_size'] : SysConst::AdminIndexPageSize;

        $list = $this->repo->getAdminGroupList($page, $pageSize, $params);

        return CommonResult::success('ok', $list);
    }

    /**
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function adminCreateGroup(array $inputParam): CommonResult
    {
        if (empty($inputParam)) {
            return CommonResult::invalidParam('参数为空');
        }

        $vResult = Validator::validate(
            $inputParam,
            [
                'title'                  => 'required|string|min:1|max:30',
                'site_bid'               => 'required|string|max:10',
                'code'                   => 'required|string|min:4|max:8',
                'open_join'              => 'required|integer|in:0,1',
                'sort'                   => 'required|integer|min:0',
                'visible'                => 'required|integer|in:0,1',
                'owner'                  => 'required',
                'speak_user_level'       => 'required|integer|min:0',
                'join_user_level'        => 'required|integer|min:0',
                'user_limit'             => 'integer|min:1|max:'.Group::MaxUserLimit,
                'bulletin'               => 'string|max:500',
                'remark'                 => 'string|max:50',
                'lobby_cover_pic_url'    => 'string|max:300',
                'my_group_cover_pic_url' => 'string|max:300',
                'admin_id'               => 'integer',
                'ext_cdn_url'            => 'required|string|min:1|max:2000',
            ],
            [
                'title.required'       => '缺少title参数',
                'site_bid.required'    => '缺少site_bid参数',
                'code.required'        => '缺少code参数',
                'code.min'             => '群号格式为长度4-8的数字',
                'code.max'             => '群号格式为长度4-8的数字',
                'open_join.required'   => '缺少open_join参数',
                'sort.required'        => '缺少sort参数',
                'owner_id.required'    => '缺少owner_id参数',
                'visible.required'     => '缺少visible参数',
                'user_limit.max'       => '群人数上限最多'.Group::MaxUserLimit.'人',
                'ext_cdn_url.required' => '未提供彩票端CDN网址',
                'ext_cdn_url.max'      => '彩票端CDN网址过长',
            ],
        );
        if ( ! $vResult->success) {
            Log::error('创建群组, 参数验证失败:'.$vResult->msg, 'create_group_err');

            return CommonResult::invalidParam($vResult->msg);
        }

        $param             = $vResult->validated;
        $param['title']    = trim($param['title']);
        $param['site_bid'] = trim($param['site_bid']);
        $param['code']     = trim($param['code']);
        $param['bulletin'] = trim($param['bulletin']);
        $param['remark']   = trim($param['remark']);

        if ( ! ctype_digit($param['code'])) {
            return CommonResult::error('群号格式错误, 请填入 4 - 8 位数字');
        }

        /** @var GroupRepository $groupRepo */
        $groupRepo = Container::get(GroupRepository::class);

        $existsGroup = $groupRepo->getByCode($param['site_bid'], $param['code']);
        if ( ! empty($existsGroup)) {
            Log::error('创建失败! 群号重复!', 'create_group_code_duplicate_err');

            return CommonResult::error('群号已重复!');
        }

        //封面上传(没给就用预设的)
        $extCdnUrl                    = trim($param['ext_cdn_url']);
        $chatLobbyCoverUrl            = '';
        $param['lobby_cover_pic_url'] = isset($param['lobby_cover_pic_url']) ? trim($param['lobby_cover_pic_url']) : '';
        if ( ! empty($param['lobby_cover_pic_url'])) {
            try {
                /** @var \App\Service\Impl\ImgUploadService $uploadService */
                $uploadService = Container::make(ImgUploadService::class, ['siteBid' => $param['site_bid']]);
                [$ok, $chatLobbyCoverUrl] = $uploadService->uploadGroupCover($param['lobby_cover_pic_url'], $extCdnUrl);
                if ( ! $ok) {
                    return CommonResult::error('群封面上传失败!');
                }
                Log::cli('群封面上传成功! 路径:'.$chatLobbyCoverUrl);
            } catch (\Throwable $e) {
                Log::error('群封面上传异常! 讯息:'.Helper::getExpDetails($e), 'upload_lobby_group_cover_err');

                return CommonResult::error('群封面上传失败!');
            }
        }
        if (empty($chatLobbyCoverUrl)) {
            /** @var ConfigRepository $configRepo */
            $configRepo        = Container::get(ConfigRepository::class);
            $chatLobbyCoverUrl = $configRepo->getByKey(ConfigKey::DefaultGroupCoverLobby);
        }

        $chatMyGroupCoverUrl             = '';
        $param['my_group_cover_pic_url'] = isset($param['my_group_cover_pic_url']) ? trim($param['my_group_cover_pic_url']) : '';
        if ( ! empty($param['my_group_cover_pic_url'])) {
            try {
                /** @var \App\Service\Impl\ImgUploadService $uploadService */
                $uploadService = Container::make(ImgUploadService::class, ['siteBid' => $param['site_bid']]);
                [$ok, $chatMyGroupCoverUrl] = $uploadService->uploadGroupCover($param['my_group_cover_pic_url'], $extCdnUrl);
                if ( ! $ok) {
                    return CommonResult::error('群封面上传失败!');
                }
                Log::cli('群封面上传成功! 路径:'.$chatMyGroupCoverUrl);
            } catch (\Throwable $e) {
                Log::error('群封面上传异常! 讯息:'.Helper::getExpDetails($e), 'upload_my_group_cover_err');

                return CommonResult::error('群封面上传失败!');
            }
        }
        if (empty($chatMyGroupCoverUrl)) {
            /** @var ConfigRepository $configRepo */
            $configRepo          = Container::get(ConfigRepository::class);
            $chatMyGroupCoverUrl = $configRepo->getByKey(ConfigKey::DefaultGroupCoverMy);
        }

        /** @var AdminUserRepository $adminUserRepo */
        $adminUserRepo = Container::get(AdminUserRepository::class);

        $now = time();
        Db::beginTransaction();
        try {
            $ownerUser = $adminUserRepo->createUserIfNotExist($param['owner'], $param['site_bid']);

            $groupToAdd             = [];
            $groupToAdd['title']    = $param['title'];
            $groupToAdd['site_bid'] = $param['site_bid'];
            $groupToAdd['code']     = strtoupper($param['code']);

            $groupToAdd['owner_user_id']       = $ownerUser['id'];
            $groupToAdd['owner_ext_member_id'] = $ownerUser['ext_member_id'];
            $groupToAdd['owner_ext_username']  = $ownerUser['ext_username'];

            $groupToAdd['lobby_cover_pic_url']        = $chatLobbyCoverUrl;
            $groupToAdd['my_group_cover_pic_url']     = $chatMyGroupCoverUrl;
            $groupToAdd['ext_lobby_cover_pic_url']    = $param['lobby_cover_pic_url'];
            $groupToAdd['ext_my_group_cover_pic_url'] = $param['my_group_cover_pic_url'];
            $groupToAdd['sort']                       = $param['sort'];
            $groupToAdd['visible']                    = $param['visible'];
            $groupToAdd['user_limit']                 = $param['user_limit'];
            $groupToAdd['bulletin']                   = Helper::secureStr($param['bulletin']);
            $groupToAdd['open_join']                  = $param['open_join'];
            $groupToAdd['speak_user_level']           = $param['speak_user_level'];
            $groupToAdd['join_user_level']            = $param['join_user_level'];
            $groupToAdd['remark']                     = Helper::secureStr($param['remark']);
            $groupToAdd['create_time']                = $now;
            $groupToAdd['update_time']                = $now;

            /** @var AdminGroupRepository $adminGroupRepo */
            $adminGroupRepo = Container::get(AdminGroupRepository::class);
            $newGroupId     = $adminGroupRepo->insertGetId($groupToAdd);
            if ( ! $newGroupId) {
                throw new \PDOException(sprintf('将群配置写入group失败! 数据: %s', json_encode($groupToAdd, 256)));
            }

            /** @var GroupUserRepository $groupUserRepo */
            $groupUserRepo = Container::get(GroupUserRepository::class);
            $groupUserRepo->add(['group_id' => $newGroupId, 'user_id' => $ownerUser['id'], 'join_time' => $now, 'update_time' => $now, 'role_type' => GroupUser::RoleOwner]);

            $logs = [];
            if ( ! empty($ownerUser)) {
                $logs[] = [
                    'type'          => UserLogBuilder::TypeBecomeGroupMaster,
                    'site_bid'      => $param['site_bid'],
                    'user_id'       => $ownerUser['id'],
                    'ext_member_id' => $ownerUser['ext_member_id'],
                    'ext_username'  => $ownerUser['ext_username'],
                    'scene'         => UserLogBuilder::SceneCpAdmin,
                    'admin_id'      => $param['admin_id'],
                    'operator_type' => UserLogBuilder::OperatorTypeOther,
                    'create_time'   => $now,
                    'remark'        => '成为群主，群号: '.$groupToAdd['code'],
                ];
            }

            if ( ! empty($logs)) {
                /** @var UserLogRepository $userLogRepo */
                $userLogRepo = Container::get(UserLogRepository::class);
                $userLogRepo->addWithParam($logs);
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error(sprintf('创建群组异常! 讯息: %s', Helper::getExpDetails($e)), 'admin_create_group_err');

            return CommonResult::error('创建失败');
        }

        Cache::del(NoSqlKey::allGroup($param['site_bid']));

        return CommonResult::success();
    }

    /**
     * 彩票后台更新群组.
     *
     * @param  array  $inputParam
     *
     * @return \App\Lib\CommonResult
     */
    public function adminUpdateGroup(array $inputParam): CommonResult
    {
        if (empty($inputParam)) {
            return CommonResult::invalidParam('参数为空');
        }

        $vResult = Validator::validate(
            $inputParam,
            [
                'group_id'               => 'required|int|min:1',
                'title'                  => 'required|string|max:30',
                'site_bid'               => 'required|string|max:10',
                'code'                   => 'required|string|min:4|max:8',
                'open_join'              => 'required|integer|in:0,1',
                'sort'                   => 'required|integer|min:0',
                'visible'                => 'required|integer|in:0,1',
                'owner'                  => 'required',
                'speak_user_level'       => 'required|integer|min:0',
                'join_user_level'        => 'required|integer|min:0',
                'user_limit'             => 'integer|min:1|max:'.Group::MaxUserLimit,
                'bulletin'               => 'string|max:1000',
                'admin_id'               => 'required|integer|min:1',
                'remark'                 => 'string|max:50',
                'lobby_cover_pic_url'    => 'string|max:300',
                'my_group_cover_pic_url' => 'string|max:300',
                'ext_cdn_url'            => 'required|string|min:1|max:2000',
            ],
            [
                'group_id.required'    => '缺少group_id参数',
                'title.required'       => '缺少title参数',
                'site_bid.required'    => '缺少site_bid参数',
                'code.required'        => '缺少code参数',
                'code.min'             => '群号格式为长度4-8的数字',
                'code.max'             => '群号格式为长度4-8的数字',
                'open_join.required'   => '缺少open_join参数',
                'sort.required'        => '缺少sort参数',
                'owner.required'       => '缺少owner参数',
                'visible.required'     => '缺少visible参数',
                'user_limit.max'       => '群人数上限最多'.Group::MaxUserLimit.'人',
                'ext_cdn_url.required' => '未提供彩票端CDN网址',
                'ext_cdn_url.max'      => '彩票端CDN网址过长',
            ],
        );
        if ( ! $vResult->success) {
            Log::error('修改群组, 参数验证失败:'.$vResult->msg, 'admin_update_group_err');

            return CommonResult::invalidParam($vResult->msg);
        }

        $now               = time();
        $param             = $vResult->validated;
        $param['title']    = trim($param['title']);
        $param['code']     = trim($param['code']);
        $param['bulletin'] = trim($param['bulletin']);
        $param['remark']   = trim($param['remark']);

        if ( ! ctype_digit($param['code'])) {
            return CommonResult::error('群号格式错误, 请填入 4 - 8 位数字');
        }

        /** @var GroupRepository $groupRepo */
        $groupRepo = Container::get(GroupRepository::class);
        $group     = $groupRepo->getById($param['group_id']);
        if ( ! $group) {
            return CommonResult::invalidParam('群组不存在');
        }

        if ($group['code'] !== $param['code']) {
            $existsGroup = $groupRepo->getByCode($group['site_bid'], $param['code']);
            if ( ! empty($existsGroup) && $existsGroup['id'] !== $group['id']) {
                Log::error('更新失败! 群号重复!', 'update_group_err');

                return CommonResult::error('群号已重复!');
            }
        }

        $groupCode = $group['code'];
        if ($group['code'] !== $param['code']) {
            $groupCode = $param['code'];
        }

        $extCdnUrl            = trim($param['ext_cdn_url']);
        $chatCdnLobbyCoverUrl = '';
        if ( ! empty($param['lobby_cover_pic_url'])) {
            try {
                /** @var \App\Service\Impl\ImgUploadService $uploadService */
                $uploadService = Container::make(ImgUploadService::class, ['siteBid' => $param['site_bid']]);
                [$lobbyUploadOk, $chatCdnLobbyCoverUrl] = $uploadService->uploadGroupCover($param['lobby_cover_pic_url'], $extCdnUrl);
                if ( ! $lobbyUploadOk) {
                    return CommonResult::error($chatCdnLobbyCoverUrl);
                }
            } catch (\Throwable $e) {
                Log::error('群封面上传异常! 讯息:'.Helper::getExpDetails($e), 'lobby_cover_upload_err');

                return CommonResult::error('群封面上传失败!');
            }
        }
        if (empty($chatCdnLobbyCoverUrl)) {
            /** @var ConfigRepository $configRepo */
            $configRepo           = Container::get(ConfigRepository::class);
            $chatCdnLobbyCoverUrl = $configRepo->getByKey(ConfigKey::DefaultGroupCoverLobby);
        }

        $chatCdnMyGroupCoverUrl = '';
        if ( ! empty($param['my_group_cover_pic_url'])) {
            try {
                /** @var \App\Service\Impl\ImgUploadService $uploadService */
                $uploadService = Container::make(ImgUploadService::class, ['siteBid' => $param['site_bid']]);
                [$myGroupUploadOk, $chatCdnMyGroupCoverUrl] = $uploadService->uploadGroupCover($param['my_group_cover_pic_url'], $extCdnUrl);
                if ( ! $myGroupUploadOk) {
                    return CommonResult::error($chatCdnMyGroupCoverUrl);
                }
            } catch (\Throwable $e) {
                Log::error('聊天列表群封面上传异常! 讯息:'.Helper::getExpDetails($e), 'my_group_cover_upload_err');

                return CommonResult::error('群封面上传失败!');
            }
        }
        if (empty($chatCdnMyGroupCoverUrl)) {
            /** @var ConfigRepository $configRepo */
            $configRepo             = Container::get(ConfigRepository::class);
            $chatCdnMyGroupCoverUrl = $configRepo->getByKey(ConfigKey::DefaultGroupCoverMy);
        }

        /** @var AdminUserRepository $adminUserRepo */
        $adminUserRepo = Container::get(AdminUserRepository::class);

        $roleChangeParam = [];
        Db::beginTransaction();
        try {
            //添加群主user
            $newOwnerUser = $adminUserRepo->createUserIfNotExist($param['owner'], $param['site_bid']);

            $groupToUpdate = [];
            /** @var GroupUserRepository $groupUserRepo */
            $groupUserRepo     = Container::get(GroupUserRepository::class);
            $groupUsers        = $groupUserRepo->getByRole($group['id'], GroupUser::RoleOwner, ['user_id']);
            $curOwnerGroupUser = $groupUsers[0] ?? null;
            if ($curOwnerGroupUser !== null && (int)$curOwnerGroupUser->user_id !== (int)$newOwnerUser['id']) {
                /** @var UserRepository $userRepo */
                $userRepo     = Container::get(UserRepository::class);
                $curOwnerUser = $userRepo->getOne($curOwnerGroupUser->user_id, ['user_level', 'ext_username', 'ext_member_id', 'id']);

                $newOwnerGroupUserCreate = false;
                $ownerGroupUser          = $groupUserRepo->getGroupUser($newOwnerUser['id'], $group['id'], ['user_id']);
                if (empty($ownerGroupUser)) {
                    $groupUserRepo->add(['group_id' => $group['id'], 'user_id' => $newOwnerUser['id'], 'role_type' => GroupUser::RoleOwner, 'join_time' => $now]);
                    $newOwnerGroupUserCreate = true;
                }

                $groupToUpdate['owner_user_id']       = $newOwnerUser['id'];
                $groupToUpdate['owner_ext_member_id'] = $newOwnerUser['ext_member_id'];
                $groupToUpdate['owner_ext_username']  = $newOwnerUser['ext_username'];
                if ( ! $newOwnerGroupUserCreate) {
                    $groupUserRepo->updateRole($group['id'], [$newOwnerUser['id']], GroupUser::RoleOwner);
                }

                $groupUserRepo->updateRole($group['id'], [$curOwnerGroupUser->user_id], GroupUser::RoleUser);
                $roleChangeParam = [
                    [
                        'group_id'   => $group['id'],
                        'user_id'    => $newOwnerUser['id'],
                        'role_type'  => GroupUser::RoleOwner,
                        'user_level' => $newOwnerUser['user_level'],
                        'title'      => Helper::buildChatUserTitle(GroupUser::RoleOwner, $newOwnerUser['user_level']),
                    ],
                    [
                        'group_id'   => $group['id'],
                        'user_id'    => $curOwnerGroupUser->user_id,
                        'role_type'  => GroupUser::RoleUser,
                        'user_level' => $curOwnerUser['user_level'],
                        'title'      => Helper::buildChatUserTitle(GroupUser::RoleUser, $curOwnerUser['user_level']),
                    ],
                ];
                $log             = [
                    [
                        'type'          => UserLogBuilder::TypeBecomeGroupMaster,
                        'site_bid'      => $param['site_bid'],
                        'user_id'       => $newOwnerUser['id'],
                        'ext_member_id' => $newOwnerUser['ext_member_id'],
                        'ext_username'  => $newOwnerUser['ext_username'],
                        'scene'         => UserLogBuilder::SceneCpAdmin,
                        'admin_id'      => $param['admin_id'],
                        'operator_type' => UserLogBuilder::OperatorTypeOther,
                        'create_time'   => $now,
                        'remark'        => '成为群主，群号: '.$groupCode,
                    ],
                    [
                        'type'          => UserLogBuilder::TypeDownToGroupUser,
                        'site_bid'      => $param['site_bid'],
                        'user_id'       => $curOwnerUser['id'],
                        'ext_member_id' => $curOwnerUser['ext_member_id'],
                        'ext_username'  => $curOwnerUser['ext_username'],
                        'scene'         => UserLogBuilder::SceneCpAdmin,
                        'admin_id'      => $param['admin_id'],
                        'operator_type' => UserLogBuilder::OperatorTypeOther,
                        'create_time'   => $now,
                        'remark'        => '降为一般成员，群号: '.$groupCode,
                    ],
                ];
            }

            $groupToUpdate['title'] = $param['title'];
            $groupToUpdate['code']  = $groupCode;

            $groupToUpdate['lobby_cover_pic_url']        = $chatCdnLobbyCoverUrl;//群聊CDN路径
            $groupToUpdate['my_group_cover_pic_url']     = $chatCdnMyGroupCoverUrl;//同上
            $groupToUpdate['ext_lobby_cover_pic_url']    = $param['lobby_cover_pic_url'];//彩票端CDN路径, 因为彩票后台要能显示, 必须另外存起来, 编辑取资料时返回这个栏位, 提交编辑时比对一下不同才上传
            $groupToUpdate['ext_my_group_cover_pic_url'] = $param['my_group_cover_pic_url'];//同上

            $groupToUpdate['sort']             = $param['sort'] ?? $group['sort'];
            $groupToUpdate['visible']          = $param['visible'] ?? $group['visible'];
            $groupToUpdate['user_limit']       = $param['user_limit'] ?? $group['user_limit'];
            $groupToUpdate['bulletin']         = Helper::secureStr($param['bulletin']) ?? $group['bulletin'];
            $groupToUpdate['open_join']        = $param['open_join'] ?? $group['open_join'];
            $groupToUpdate['speak_user_level'] = $param['speak_user_level'] ?? $group['speak_user_level'];
            $groupToUpdate['join_user_level']  = $param['join_user_level'] ?? $group['join_user_level'];
            $groupToUpdate['remark']           = Helper::secureStr($param['remark']) ?? $group['remark'];
            $groupToUpdate['update_time']      = $now;

            /** @var AdminGroupRepository $adminGroupRepo */
            $adminGroupRepo = Container::get(AdminGroupRepository::class);
            $groupUpdated   = $adminGroupRepo->updateGroup($group['id'], $groupToUpdate);
            if ( ! $groupUpdated) {
                throw new \PDOException(sprintf('group update失败! 数据: %s', json_encode($groupToUpdate, 256)));
            }

            if ( ! empty($log)) {
                /** @var UserLogRepository $userLogRepo */
                $userLogRepo = Container::get(UserLogRepository::class);
                $userLogRepo->addWithParam($log);
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error(sprintf('修改群组异常! 讯息: %s', Helper::getExpDetails($e)), 'admin_update_group_err');

            return CommonResult::error('修改失败');
        }

        if ( ! empty($roleChangeParam)) {
            foreach ($roleChangeParam as $item) {
                RStream::push(RStream::TypeNotifyUserRoleChange, $item);
            }
        }

        //状态更新, 聊天列表跟大厅的显示资讯就不通知了, 直接让client刷新即可.
        $notifyState = [];
        if ($group['title'] !== $groupToUpdate['title']) {
            $notifyState['title'] = $groupToUpdate['title'];
        }

        if ($group['code'] !== $groupToUpdate['code']) {
            $notifyState['code'] = $groupToUpdate['code'];
        }

        if ((int)$group['speak_user_level'] !== (int)$groupToUpdate['speak_user_level']) {
            $notifyState['speak_user_level'] = $groupToUpdate['speak_user_level'];
        }

        if ( ! empty($notifyState)) {
            $notifyState['group_id'] = $group['id'];
            RStream::push(RStream::TypeNotifyGroupState, $notifyState);
        }

        Cache::del(NoSqlKey::allGroup($param['site_bid']));

        return CommonResult::success();
    }

    /**
     * 更新群组额度.
     *
     * @param  array  $param
     *
     * @return \App\Lib\CommonResult
     */
    public function adminUpdateGroupQuota(array $param): CommonResult
    {
        $vResult = Validator::validate(
            $param,
            [
                'group_id'          => 'required|integer',
                'lucky_money_quota' => 'required|numeric',
                'game_coin_quota'   => 'required|numeric',
                'alter_type'        => 'required|integer|in:0,1',
                'admin_id'          => 'required|integer',
            ],
        );
        if ( ! $vResult->success) {
            return CommonResult::invalidParam('参数错误!'.$vResult->msg);
        }

        $validated = $vResult->validated;
        if ($validated['lucky_money_quota'] <= 0 && $validated['game_coin_quota'] <= 0) {
            return CommonResult::success('额度无需变更');
        }

        /** @var GroupRepository $groupRepo */
        $groupRepo = Container::get(GroupRepository::class);
        $group     = $groupRepo->getById($validated['group_id']);
        if ( ! $group) {
            return CommonResult::make(ErrorCode::ErrGroupNotExists, '群组不存在');
        }
        $group['lucky_money_quota']     = (string)$group['lucky_money_quota'];
        $group['game_coin_quota']       = (string)$group['game_coin_quota'];
        $validated['lucky_money_quota'] = isset($validated['lucky_money_quota']) ? (string)$validated['lucky_money_quota'] : 0;
        $validated['game_coin_quota']   = isset($validated['game_coin_quota']) ? (string)$validated['game_coin_quota'] : 0;

        $newQuota = [];
        if ($validated['alter_type'] > 0) {
            if ($validated['lucky_money_quota'] > 0) {
                $newQuota['lucky_money_quota'] = bcadd($group['lucky_money_quota'], $validated['lucky_money_quota'], SysConst::MoneyDecimal);
            }
            if ($validated['game_coin_quota'] > 0) {
                $newQuota['game_coin_quota'] = bcadd($group['game_coin_quota'], $validated['game_coin_quota'], SysConst::MoneyDecimal);
            }
        } else {
            if ($validated['lucky_money_quota'] > 0) {
                if ($group['lucky_money_quota'] <= 0 || bcsub($group['lucky_money_quota'], $validated['lucky_money_quota'], SysConst::MoneyDecimal) < 0) {
                    return CommonResult::make(ErrorCode::ErrInvalidOperate, '操作失败! 平台彩金红包额度将小于0，无法扣减!');
                }
                $newQuota['lucky_money_quota'] = bcsub($group['lucky_money_quota'], $validated['lucky_money_quota'], SysConst::MoneyDecimal);
            }
            if ($validated['game_coin_quota'] > 0) {
                if ($group['lucky_money_quota'] <= 0 || bcsub($group['game_coin_quota'], $validated['game_coin_quota'], SysConst::MoneyDecimal) < 0) {
                    return CommonResult::make(ErrorCode::ErrInvalidOperate, '操作失败! 游戏专属红包额度将小于0，无法扣减!');
                }
                $newQuota['game_coin_quota'] = bcsub($group['game_coin_quota'], $validated['game_coin_quota'], SysConst::MoneyDecimal);
            }
        }

        if (empty($newQuota)) {
            return CommonResult::success('额度无须变更');
        }

        $logs = [];
        /** @var GroupQuotaLogRepository $quotaLogRepo */
        $quotaLogRepo = Container::get(GroupQuotaLogRepository::class);
        if ($validated['alter_type'] > 0) {
            if ( ! empty($validated['lucky_money_quota']) && $validated['lucky_money_quota'] > 0) {
                $logs[] = $quotaLogRepo->buildAdminAdd(
                    $group['site_bid'],
                    $group['id'],
                    $param['admin_id'],
                    GroupQuotaLog::QuotaTypeLuckyMoney,
                    $validated['lucky_money_quota'],
                    GroupQuotaLog::ActionTypeAdminAdd,
                );
            }

            if ( ! empty($validated['game_coin_quota']) && $validated['game_coin_quota'] > 0) {
                $logs[] = $quotaLogRepo->buildAdminAdd(
                    $group['site_bid'],
                    $group['id'],
                    $param['admin_id'],
                    GroupQuotaLog::QuotaTypeGameCoin,
                    $validated['lucky_money_quota'],
                    GroupQuotaLog::ActionTypeAdminAdd,
                );
            }
        } else {
            if ( ! empty($validated['lucky_money_quota']) && $validated['lucky_money_quota'] > 0) {
                $logs[] = $quotaLogRepo->buildAdminSub(
                    $group['site_bid'],
                    $group['id'],
                    $param['admin_id'],
                    GroupQuotaLog::QuotaTypeLuckyMoney,
                    $validated['lucky_money_quota'],
                    GroupQuotaLog::ActionTypeAdminSub,
                );
            }

            if ( ! empty($validated['game_coin_quota']) && $validated['game_coin_quota'] > 0) {
                $logs[] = $quotaLogRepo->buildAdminSub(
                    $group['site_bid'],
                    $group['id'],
                    $param['admin_id'],
                    GroupQuotaLog::QuotaTypeGameCoin,
                    $validated['lucky_money_quota'],
                    GroupQuotaLog::ActionTypeAdminSub,
                );
            }
        }

        Db::beginTransaction();
        try {
            /** @var AdminGroupRepository $adminGroupRepo */
            $adminGroupRepo = Container::get(AdminGroupRepository::class);
            $ok             = $adminGroupRepo->updateGroup($group['id'], $newQuota);
            if ( ! $ok) {
                throw new \PDOException('更新群额度失败!');
            }

            if ( ! empty($logs)) {
                $quotaLogRepo->add($logs);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error(sprintf('更新群额度异常! 参数: %s, 讯息: %s', json_encode($newQuota, 256), Helper::getExpDetails($e)));

            return CommonResult::error('更新失败');
        }

        Cache::del(NoSqlKey::allGroup($group['site_bid']));

        return CommonResult::success('更新成功');
    }

    /**
     * 查询群号是否存在.
     *
     * @param  array  $param
     *
     * @return CommonResult
     */
    public function findGroupById(array $param): CommonResult
    {
        $vResult = Validator::validate(
            $param,
            ['id' => 'required|integer', 'site_bid' => 'required|string'],
            ['id.required' => '缺少id参数', 'site_bid.required' => '缺少site_bid参数'],
        );
        if (empty($vResult->success)) {
            Log::error(json_encode($vResult->errors, 256));

            return CommonResult::invalidParam('参数验证失败:'.$vResult->msg);
        }

        /** @var GroupRepository $groupRepo */
        $groupRepo = Container::get(GroupRepository::class);
        $group     = $groupRepo->getById($vResult->validated['id']);
        if (empty($group)) {
            return CommonResult::notFound('该群组不存在');
        }

        if ( ! empty($group['ext_lobby_cover_pic_url'])) {
            $group['lobby_cover_pic_url'] = $group['ext_lobby_cover_pic_url'];
        }

        if ( ! empty($group['ext_my_group_cover_pic_url'])) {
            $group['my_group_cover_pic_url'] = $group['ext_my_group_cover_pic_url'];
        }

        $group['mod_ids']            = [];
        $group['mod_ext_member_ids'] = [];
        $group['mod_ext_usernames']  = [];
        /** @var AdminGroupRepository $adminGroupRepo */
        $adminGroupRepo = Container::get(AdminGroupRepository::class);
        $ownerAndMod    = $adminGroupRepo->getOwnerModInfo($group['id']);
        if ( ! empty($ownerAndMod)) {
            foreach ($ownerAndMod as $userInfo) {
                if ((int)$userInfo->role_type === GroupUser::RoleOwner) {
                    $group['owner_id']            = $userInfo->id;
                    $group['owner_ext_member_id'] = $userInfo->ext_member_id;
                    $group['owner_ext_username']  = $userInfo->ext_username;
                }

                if ((int)$userInfo->role_type === GroupUser::RoleMod) {
                    $group['mod_ids'][]            = $userInfo->id;
                    $group['mod_ext_member_ids'][] = $userInfo->ext_member_id;
                    $group['mod_ext_usernames'][]  = $userInfo->ext_username;
                }
            }
        }

        return CommonResult::success('ok', $group);
    }

    /**
     * 以群号查找群组
     *
     * @param  array  $param
     *
     * @return CommonResult
     */
    public function findGroupByCode(array $param): CommonResult
    {
        $vResult = Validator::validate(
            $param,
            ['code' => 'required|string', 'site_bid' => 'required|string'],
            ['code.required' => '缺少code参数', 'site_bid.required' => '缺少site_bid参数'],
        );
        if (empty($vResult->success)) {
            Log::error(json_encode($vResult->errors, 256));

            return CommonResult::invalidParam('参数验证失败:'.$vResult->msg);
        }

        /** @var GroupRepository $groupRepo */
        $groupRepo = Container::get(GroupRepository::class);
        $group     = $groupRepo->getByCode($vResult->validated['site_bid'], $vResult->validated['code'], ['id', 'title']);
        $group     = ! empty($group) ? $group : [];

        return CommonResult::success('ok', ['group' => $group]);
    }

    /**
     * 取后台群额度纪录
     *
     * @param  array  $param
     *
     * @return \App\Lib\CommonResult
     */
    public function getGroupQuotaLog(array $param): CommonResult
    {
        /** @var AdminGroupRepository $adminGroupRepo */
        $adminGroupRepo = Container::get(AdminGroupRepository::class);

        $page      = isset($param['page']) ? (int)$param['page'] : 1;
        $pageSize  = isset($param['page_size']) ? (int)$param['page_size'] : SysConst::AdminIndexPageSize;
        $groupMenu = $adminGroupRepo->getAdminGroupMenu($param['site_bid'], ['id', 'title', 'code']);
        $groupMap  = array_column($groupMenu, null, 'id');
        $data      = $adminGroupRepo->getAdminGroupQuotaLog($page, $pageSize, $param);
        if ( ! empty($data['model'])) {
            foreach ($data['model'] as $each) {
                $each->group_title = $groupMap[$each->group_id]->title ?? '';
                $each->group_code  = $groupMap[$each->group_id]->code ?? '';
            }
        }
        $data['group_menu'] = $groupMenu;

        return CommonResult::success('ok', $data);
    }

}