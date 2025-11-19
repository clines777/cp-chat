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
use App\Model\ChatRecord;
use App\Model\GroupUser;
use App\Model\LuckyMoney;
use App\Repository\Impl\AvatarRepository;
use App\Repository\Impl\ChatRepository;
use App\Repository\Impl\ConfigRepository;
use App\Repository\Impl\GroupUserRepository;

class ChatService
{

    protected ChatRepository $chatRepo;

    public function __construct(ChatRepository $chatRepository)
    {
        $this->chatRepo = $chatRepository;
    }

    /**
     * 添加用户聊天纪录
     *
     * @param  int     $userId    用户ID
     * @param  string  $siteBid   站点编号
     * @param  int     $groupId   群ID
     * @param  array   $data      输入资料.
     * @param  int     $type      消息类型
     * @param  bool    $allowUrl  是否允许网址
     * @param  array   $extra     额外讯息(红包)
     * @param  int     $customId  红包ID
     *
     * @return \App\Lib\CommonResult
     */
    public function addChatMessage(int $userId, string $siteBid, int $groupId, array $data, int $type, bool $allowUrl, array $extra = [], int $customId = 0): CommonResult
    {
        $data['content'] = isset($data['content']) ? trim($data['content']) : '';
        if ($data['content'] === '') {
            return CommonResult::make(ErrorCode::ErrSendChatEmpty, '发送讯息为空');
        }

        if ( ! $allowUrl && Helper::containsUrl($data['content'])) {
            return CommonResult::make(ErrorCode::ErrSendChatContainsUrl, '输入内容不可包含链接网址');
        }

        $badWords = '';//敏感词过滤, 但找的Lib一直有问题, 先不做.
        $newId    = $this->chatRepo->addChat($type, $userId, $siteBid, $groupId, htmlspecialchars($data['content']), $extra, $badWords, $customId);
        if ( ! $newId) {
            return CommonResult::error('讯息写入失败');
        }

        $newChat = $this->chatRepo->getOneForDisplay($newId);

        return CommonResult::success('ok', $newChat);
    }

    /**
     * 组建聊天讯息
     *
     * @param  array  $rawList
     *
     * @return array
     */
    public function buildChatList(array $rawList): array
    {
        if (empty($rawList)) {
            return [];
        }

        /** @var ConfigRepository $configService */
        $configRepo = Container::get(ConfigRepository::class);
        $cdnUrl     = $configRepo->getByKey(ConfigKey::CdnUrl);

        /** @var AvatarRepository $avatarRepo */
        $avatarRepo = Container::get(AvatarRepository::class);
        $avatarMap  = $avatarRepo->getMap();

        $now      = time();
        $chatList = [];
        foreach ($rawList as $chat) {
            $chat->role_type  = (int)$chat->role_type;
            $chat->user_level = (int)$chat->user_level;
            $chat->is_ban     = (int)$chat->is_ban;

            $title     = Helper::buildChatUserTitle($chat->role_type, $chat->user_level);
            $name      = Helper::maskUsername($chat->ext_username);
            $dateTime  = date('m-d H:i', $chat->create_time);
            $avatarUrl = ! empty($avatarMap[$chat->avatar_id]) ? $cdnUrl.$avatarMap[$chat->avatar_id] : '';

            $extra = [];
            if ((int)$chat->type === ChatRecord::TypeLmMsg) {
                $extra = static::buildChatLmExtra($chat, $now);
            }

            $chatList[] = [
                'type'     => (int)$chat->type,
                'id'       => (int)$chat->id,
                'user'     => [
                    'id'         => (int)$chat->user_id,
                    'name'       => $name,
                    'level'      => $chat->user_level,
                    'role_type'  => $chat->role_type,
                    'title'      => $title,
                    'avatar_url' => $avatarUrl,
                    'is_ban'     => $chat->is_ban,
                ],
                'content'  => htmlspecialchars($chat->content),
                'datetime' => ['text' => $dateTime, 'time' => (int)$chat->create_time],
                'extra'    => $extra,
            ];
        }

        return $chatList;
    }

    /**
     * 组建聊天讯息的红包资讯.
     *
     * @param  object  $chat
     * @param  int     $nowTime
     *
     * @return array
     */
    public static function buildChatLmExtra(object $chat, int $nowTime): array
    {
        $extra           = [];
        $lmInfo          = ! empty($chat->extra) && json_validate($chat->extra) ? json_decode($chat->extra, true) : [];
        $lmId            = (int)$lmInfo['lm_id'];
        $lmBonusTypeText = (int)$lmInfo['bonus_type'] === LuckyMoney::BonusTypeLuckyMoney ? '平台彩金红包' : '游戏专属红包';
        $lmState         = $lmInfo['end_time'] < $nowTime ? ChatRecord::LmChatStateExpired : ChatRecord::LmChatStateNormal;
        $extra['lm']     = [
            'id'         => $lmId,
            'creator_id' => (int)$lmInfo['creator_id'],
            'bonus_type' => $lmBonusTypeText,
            'end_time'   => (int)$lmInfo['end_time'],
            'state'      => $lmState,
        ];

        return $extra;
    }

    /**
     * 取得用户进群的初始所需资讯.
     *
     * @param  array  $group
     * @param  array  $groupUser
     *
     * @return array
     */
    public function getEnterGroupInitInfo(array $group, array $groupUser): array
    {
        $chatList = [];
        try {
            /** @var ConfigRepository $configRepo */
            $configRepo = Container::get(ConfigRepository::class);

            $count       = (int)$configRepo->getByKey(ConfigKey::GroupLastChatCount);
            $rawChatList = $this->chatRepo->getLastChats($group['id'], $groupUser['last_visible_chat_id'], $count);
            if (empty($rawChatList)) {
                return [];
            }

            $chatList = $this->mappingChatUserInfo($group['id'], $rawChatList);
            if (empty($chatList)) {
                return [];
            }

            $chatList = $this->buildChatList($chatList);
        } catch (\Throwable $e) {
            Log::error('处理进群初始化资料异常! 讯息: '.Helper::getExpDetails($e), 'getEnterGroupInitInfo_err');
        }

        return $chatList;
    }

    /**
     * 格式化Pin聊天消息.
     *
     * @param  array  $chat
     *
     * @return array
     */
    public function formatPinChat(array $chat): array
    {
        if (empty($chat)) {
            return [];
        }

        return ['content' => $chat['content'], 'id' => $chat['id'], 'group_id' => $chat['group_id']];
    }

    /**
     * 取得置顶消息.
     *
     * @param  int  $chatId
     *
     * @return array
     */
    public function getPinChat(int $chatId): array
    {
        $chat = $this->chatRepo->getById($chatId);
        if ( ! $chat) {
            return [];
        }

        return $this->formatPinChat($chat);
    }

    /**
     * 清空聊天群对话(隐藏)
     *
     * @param  array  $user
     * @param  array  $param
     *
     * @return \App\Lib\CommonResult
     */
    public function cleanChat(array $user, array $param): CommonResult
    {
        $vResult = Validator::validate($param, ['group_id' => 'required|integer']);
        if ( ! $vResult->success) {
            return CommonResult::invalidParam('参数验证错误'.$vResult->msg);
        }

        /** @var GroupUserRepository $groupUserRepo */
        $groupUserRepo = Container::get(GroupUserRepository::class);
        $groupUser     = $groupUserRepo->getGroupUser($user['id'], $param['group_id'], ['id', 'last_read_chat_id', 'last_visible_chat_id']);
        if (empty($groupUser)) {
            return CommonResult::error('不属于该群组');
        }

        $lastChatId = $this->chatRepo->getLastChatIdOfGroup($param['group_id']);
        if ($lastChatId <= 0) {
            return CommonResult::success('操作成功');
        }

        $update = ['last_visible_chat_id' => $lastChatId];
        if ($groupUser['last_read_chat_id'] < $lastChatId) {
            $update['last_read_chat_id'] = $lastChatId;
        }

        $ok = $groupUserRepo->updateLastVisibleChat($groupUser['id'], $update);
        if ( ! $ok) {
            return CommonResult::error('操作失败');
        }

        Cache::del(NoSqlKey::extUnread($user['site_bid'], $user['ext_member_id']));

        return CommonResult::success('操作成功');
    }

    /**
     * 取单笔消息.
     *
     * @param  int  $chatId
     *
     * @return array
     */
    public function getChatById(int $chatId): array
    {
        return $this->chatRepo->getById($chatId);
    }

    /**
     * 删除讯息.
     *
     * @param  int  $chatId
     *
     * @return bool
     */
    public function softDelChat(int $chatId): bool
    {
        //TODO: 加操作log
        return $this->chatRepo->softDeleteChat($chatId);
    }

    /**
     * 填充各种聊天讯息所需附带资讯
     *
     * @param  array  $chat
     *
     * @return array
     */
    public function decorateNewChat(array $chat): array
    {
        $chatList   = $this->mappingChatUserInfo($chat['group_id'], [(object)$chat]);
        $builtChats = $this->buildChatList($chatList);
        if (empty($builtChats[0])) {
            return [];
        }

        return $builtChats[0];
    }

    /**
     * @param  int  $groupId            群ID
     * @param  int  $beforeChatId       从这笔消息ID往前拉
     * @param  int  $lastVisibleChatId  用户最新可见消息ID(清空消息时更新)
     * @param  int  $count              拉取筆數.
     *
     * @return array
     */
    public function getHistory(int $userId, int $groupId, int $beforeChatId, int $lastVisibleChatId, int $count = 30): array
    {
        $rawChatList = $this->chatRepo->getChatsBefore($groupId, $beforeChatId, $lastVisibleChatId, $count);
        if (empty($rawChatList)) {
            return [];
        }
        $chatList = $this->mappingChatUserInfo($groupId, $rawChatList);

        $chatList = $this->buildChatList($chatList);
        if ( ! empty($chatList)) {
            foreach ($chatList as $idx => $chat) {
                $chatList[$idx]['is_client'] = $userId === (int)$chat['user']['id'] ? 1 : 0;
            }
        }

        return $chatList;
    }

    /**
     * 填充聊天讯息所需附加栏位.
     *
     * @param  int    $groupId
     * @param  array  $rawChatList
     *
     * @return array
     */
    public function mappingChatUserInfo(int $groupId, array $rawChatList): array
    {
        $userIds = array_column($rawChatList, 'user_id');
        $userIds = array_unique($userIds);
        /** @var GroupUserRepository $groupUserRepo */
        $groupUserRepo = Container::get(GroupUserRepository::class);
        $groupUsers    = $groupUserRepo->getJoinUsers(
            $groupId,
            $userIds,
            ['group_user.role_type', 'user.ext_username', 'user.avatar_id', 'user.user_level', 'user.id as user_id', 'group_user.is_ban'],
        );
        if (empty($groupUsers)) {
            return [];
        }
        $groupUserMap = array_column($groupUsers, null, 'user_id');

        $chatList = [];
        foreach ($rawChatList as $each) {
            $userInfo = $groupUserMap[$each->user_id] ?? [];
            if (empty($userInfo)) {
                Log::error(sprintf('未匹配到讯息的用户资讯! 讯息: %s, 用户Map: %s', json_encode($each, 256), json_encode($groupUserMap, 256)), 'mappingChatUserInfo_err');
                continue;
            }

            if ($userInfo->role_type === null) {
                $userInfo->role_type = GroupUser::RoleQuit;
            }
            $each->role_type    = (int)$userInfo->role_type;
            $each->ext_username = $userInfo->ext_username;
            $each->avatar_id    = $userInfo->avatar_id;
            $each->user_level   = (int)$userInfo->user_level;
            $each->is_ban       = (int)$userInfo->is_ban;

            $chatList[] = $each;
        }

        return $chatList;
    }

    /**
     * 检查用户是否可观看领取详情.
     *
     * @param  mixed  $chat
     * @param  int    $clientUserId
     *
     * @return int
     */
    public static function getUserDetailViewable(mixed $chat, int $clientUserId): int
    {
        if ( ! ($chat instanceof \stdClass)) {
            $chat = (object)$chat;
        }

        if ((int)$chat->type !== ChatRecord::TypeLmMsg) {
            return 0;
        }

        return ! empty($chat->extra?->lm?->creator_id) && (int)$chat->extra->lm->creator_id === $clientUserId ? 1 : 0;
    }

}