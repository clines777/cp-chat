<?php

namespace App\Service\Impl;

use App\Lib\ConfigKey;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Helper;
use App\Lib\MsgPayload;
use App\Lib\MsgType;
use App\Model\ChatRecord;
use App\Repository\Impl\ChatRepository;
use App\Repository\Impl\ConfigRepository;
use App\Repository\Impl\GroupUserRepository;
use App\Repository\Impl\SceneRepository;
use App\Repository\Impl\SessionRepository;
use App\Repository\Impl\SysChatRepository;
use App\Repository\Impl\SysLmUserRepository;
use App\Repository\Impl\UserRepository;

/**
 * 各种广播推送
 */
class NotifyService
{

    protected GroupUserRepository $groupUserRepo;

    public function __construct(GroupUserRepository $groupUserRepo)
    {
        $this->groupUserRepo = $groupUserRepo;
    }

    /**
     * 通知用户被Ban
     *
     * @param mixed $server
     * @param array $groupUser
     *
     * @return void
     */
    public function notifyUserBanned(mixed $server, array $groupUser): void
    {
        $onlineInfo = $this->groupUserRepo->rGetGroupOnlineUser($groupUser['group_id'], $groupUser['user_id']);
        if (empty($onlineInfo)) {
            Log::info('用户禁言, 用户不在群内, 略过后续处理', 'notifyUserBanned_skip');

            return;
        }

        if ($onlineInfo['fd'] <= 0) {
            Log::info('用户禁言, 无效的用户连线', 'notifyUserBanned_fd_error');

            return;
        }

        Helper::push(
            $server,
            (int)$onlineInfo['fd'],
            MsgPayload::make(MsgType::UserBanAck, ['user_id' => (int)$groupUser['user_id']])
        );
    }

    /**
     * @param mixed $server
     * @param array $groupUser
     *
     * @return void
     */
    public function notifyUserUnbanned(mixed $server, array $groupUser): void
    {
        //先检查是否在线再看要不要修改线上状态.
        $onlineInfo = $this->groupUserRepo->rGetGroupOnlineUser($groupUser['group_id'], $groupUser['user_id']);
        if (empty($onlineInfo)) {
            Log::info('用户解禁, 用户不在群内, 略过通知', 'unban_user_info');

            return;
        }

        if ($onlineInfo['fd'] <= 0) {
            return;
        }

        Helper::push(
            $server,
            (int)$onlineInfo['fd'],
            MsgPayload::make(MsgType::UserUnbanAck, ['user_id' => (int)$groupUser['user_id']])
        );
    }

    /**
     * @param mixed $server
     * @param array $groupUser
     *
     * @return void
     */
    public function notifyUserKicked(mixed $server, array $groupUser): void
    {
        $tarGetUserId = (int)$groupUser['user_id'];
        $onlineUsers = $this->groupUserRepo->rGetGroupOnlineUsers($groupUser['group_id']);
        if (empty($onlineUsers)) {
            Log::info('用户踢群, 群内当前无用户在线, 略过后续处理', 'notifyUserKicked_info');

            return;
        }
        /** @var UserRepository $userRepo */
        $userRepo = Container::get(UserRepository::class);
        $user = $userRepo->getOne($tarGetUserId);
        if (empty($user)) {
            Log::error('用户踢群, 未查找到该用户, 用户ID: ' . $tarGetUserId, 'notifyUserKicked_err');

            return;
        }

        $targetUsername = Helper::maskUsername($user['ext_username']);
        $castText = sprintf('已踢出群组%s用户', $targetUsername);

        $ackPayLoad = MsgPayload::make(MsgType::KickUserAck, ['user_id' => $tarGetUserId]);
        $castPayLoad = MsgPayload::make(
            MsgType::KickUserCast,
            ['user' => ['id' => $tarGetUserId, 'display_text' => $castText]]
        );
        foreach ($onlineUsers as $userId => $info) {
            $fd = isset($info['fd']) ? (int)$info['fd'] : 0;
            $userId = (int)$userId;
            if ($userId === $tarGetUserId) {
                Helper::push($server, $fd, $ackPayLoad);
            } else {
                Helper::push($server, $fd, $castPayLoad);
            }
        }
    }

    /**
     * 通知群组已解散
     *
     * @param \Swoole\WebSocket\Server $server
     * @param array $data
     *
     * @return void
     */
    public function notifyGroupDismiss(mixed $server, array $data): void
    {
        $groupOnlineUsers = $this->groupUserRepo->rGetGroupOnlineUsers($data['group_id']);
        if (empty($groupOnlineUsers)) {
            Log::info('当前群内无用户在线, 略过通知', 'notify_group_dismiss');

            return;
        }

        foreach ($groupOnlineUsers as $groupUser) {
            Helper::push($server, (int)$groupUser['fd'], MsgPayload::make(MsgType::GroupDismissCast));
        }
        $this->groupUserRepo->rDelGroupOnline($data['group_id']);
    }

    /**
     * 通知有用户退群.
     *
     * @param mixed $server
     * @param array $data
     *
     * @return void
     */
    public function notifyUserQuitGroup(mixed $server, array $data): void
    {
        $groupId = (int)$data['group_id'];
        $name = $data['name'] ?? '';
        $targetUserId = (int)$data['user_id'];
        /** @var GroupUserRepository $groupUserRepo */
        $groupUserRepo = Container::get(GroupUserRepository::class);
        $groupUserRepo->rDelOnlineUser($groupId, $targetUserId);
        $onlineUsers = $groupUserRepo->rGetGroupOnlineUsers($groupId);
        if (empty($onlineUsers)) {
            Log::info('群内无用户在线, 略过通知', 'notifyUserQuitGroup_skip');

            return;
        }

        $text = sprintf('用户%s已退群', $name);
        foreach ($onlineUsers as $userId => $info) {
            $fd = isset($info['fd']) ? (int)$info['fd'] : 0;
            if ($userId !== $targetUserId) {
                Helper::push($server, $fd, MsgPayload::make(MsgType::UserQuitCast, ['text' => $text]));
            }
        }
    }

    /**
     * 1. 对群内用户发送红包讯息 2. TODO: 后面实作有开启群红包通知用户的对象要额外打通知.
     *
     * @param mixed $server
     * @param array $lmChat
     *
     * @return void
     */
    public function notifyLmStart(mixed $server, array $lmChat): void
    {
        /** @var \App\Service\Impl\ChatService $chatService */
        $chatService = Container::get(ChatService::class);
        $newChat = $chatService->decorateNewChat($lmChat);
        if (empty($newChat)) {
            Log::error(
                sprintf('填充聊天讯息失败!  原始数据: %s', json_encode($lmChat, 256)),
                'lm_decorate_chat_failed'
            );

            return;
        }

        $users = $this->groupUserRepo->rGetGroupOnlineUsers($lmChat['group_id']);
        if (empty($users)) {
            Log::info('红包消息推播 查无在线用户! 省略当前步骤');

            return;
        }

        foreach ($users as $user) {
            Helper::push($server, (int)$user['fd'], MsgPayload::make(MsgType::CastChat, $newChat));
        }
    }

    /**
     * 通知紅包收回.
     *
     * @param mixed $server
     * @param array $msg
     *
     * @return void
     */
    public function notifyGroupLmClose(mixed $server, array $msg): void
    {
        if (empty($msg['lm_id']) || !isset($msg['group_id'], $msg['user_id'], $msg['close_type'])) {
            Log::error('通知紅包结束錯誤, 參數錯誤:' . json_encode($msg, 256), 'notifyLmClose');

            return;
        }

        /** @var ChatRepository $chatRepo */
        $chatRepo = Container::get(ChatRepository::class);
        $chat = $chatRepo->getByLmId(ChatRecord::TypeLmMsg, $msg['lm_id']);
        if (empty($chat)) {
            Log::error('通知紅包结束錯誤, 未找到對應訊息' . json_encode($msg, 256), 'notifyLmClose');

            return;
        }

        /** @var GroupUserRepository $groupUserRepo */
        $groupUserRepo = Container::get(GroupUserRepository::class);
        $onlineUsers = $groupUserRepo->rGetGroupOnlineUsers($msg['group_id']);
        if (empty($onlineUsers)) {
            Log::info('通知紅包结束略過, 群組無人在線! 群ID: ' . $msg['group_id'], 'notifyLmClose');

            return;
        }

        foreach ($onlineUsers as $info) {
            Helper::push($server, (int)$info['fd'], MsgPayload::make(MsgType::LmClosed, [
                'chat' => [
                    'id' => (int)$chat['id'],
                    'user_id' => (int)$msg['user_id'],
                    'lm_id' => (int)$msg['lm_id'],
                ],
                'group_id' => (int)$msg['group_id'],
                'close_type' => (int)$msg['close_type'],
            ]));
        }
    }

    /**
     * @param mixed $server
     * @param array $msg
     * @param string $wsMsgType
     *
     * @return void
     */
    public function notifyGlobalBan(mixed $server, array $msg, string $wsMsgType): void
    {
        $msg['user_id'] = isset($msg['user_id']) ? (int)$msg['user_id'] : 0;
        if ($msg['user_id'] <= 0) {
            return;
        }

        /** @var SessionRepository $sessionRepo */
        $sessionRepo = Container::get(SessionRepository::class);
        $fd = $sessionRepo->rGetExistsUserFd($msg['user_id']);
        if (empty($fd)) {
            //用户不在线上, 不用通知
            return;
        }

        Helper::push($server, $fd, MsgPayload::make($wsMsgType, ['user_id' => $msg['user_id']]));
    }

    /**
     * 通知消息已删除.
     *
     * @param mixed $server
     * @param array $msg
     *
     * @return void
     */
    public function notifyDelChat(mixed $server, array $msg): void
    {
        if (empty($msg['chat_id']) || empty($msg['group_id'])) {
            Log::error('删除消息广播通知失败, 缺少chat_id:' . json_encode($msg, 256), 'notifyDelChat_err');

            return;
        }
        $chatId = (int)$msg['chat_id'];

        $onlineUsers = $this->groupUserRepo->rGetGroupOnlineUsers($msg['group_id']);
        if (empty($onlineUsers)) {
            return;
        }

        foreach ($onlineUsers as $info) {
            $fd = isset($info['fd']) ? (int)$info['fd'] : 0;
            Helper::push($server, $fd, MsgPayload::make(MsgType::DelChatCast, [
                'chat_id' => $chatId,
                'msg' => '违规消息已被删除',
            ]));
        }
    }

    /**
     * 通知更新群内状态(eg. 在线人数.)
     *
     * @param mixed $server
     * @param array $msg
     *
     * @return void
     */
    public function notifyGroupState(mixed $server, array $msg): void
    {
        if (empty($msg['group_id'])) {
            Log::error('推送群状态更新失败, 参数错误:' . json_encode($msg, 256), 'notifyGroupState_err');

            return;
        }

        $onlineUsers = $this->groupUserRepo->rGetGroupOnlineUsers($msg['group_id']);
        if (empty($onlineUsers)) {
            return;
        }

        $state = ['online_count' => count($onlineUsers)];
        if (!empty($msg['user_count'])) {
            $state['user_count'] = (int)$msg['user_count'];
        }

        if (!empty($msg['title'])) {
            $state['title'] = $msg['title'];
        }

        if (!empty($msg['code'])) {
            $state['code'] = $msg['code'];
        }

        if (isset($msg['speak_user_level'])) {
            $state['speak_user_level'] = (int)$msg['speak_user_level'];
        }

        foreach ($onlineUsers as $info) {
            $fd = isset($info['fd']) ? (int)$info['fd'] : 0;
            Helper::push($server, $fd, MsgPayload::make(MsgType::GroupStateCast, $state));
        }
    }

    /**
     * 通知消息置顶.
     *
     * @param mixed $server
     * @param array $msg
     *
     * @return void
     */
    public function notifyPinChat(mixed $server, array $msg): void
    {
        if (empty($msg['id']) || empty($msg['content']) || empty($msg['group_id'])) {
            Log::error('推送消息置顶通知失败, 参数错误:' . json_encode($msg, 256), 'notifyPinChat_err');

            return;
        }

        $chatInfo = ['chat_id' => (int)$msg['id'], 'content' => $msg['content']];

        $onlineUsers = $this->groupUserRepo->rGetGroupOnlineUsers($msg['group_id']);
        if (empty($onlineUsers)) {
            return;
        }

        foreach ($onlineUsers as $info) {
            $fd = isset($info['fd']) ? (int)$info['fd'] : 0;
            Helper::push($server, $fd, MsgPayload::make(MsgType::PinChatCast, $chatInfo));
        }
    }

    /**
     * 通知取消消息置顶.
     *
     * @param mixed $server
     * @param array $msg
     *
     * @return void
     */
    public function notifyUnpinChat(mixed $server, array $msg): void
    {
        if (empty($msg['group_id']) || empty($msg['chat_id'])) {
            Log::error('通知撤销置顶消息失败! 参数错误' . json_encode($msg, 256), 'notifyUnpinChat_err');

            return;
        }

        $msg['chat_id'] = (int)$msg['chat_id'];
        $msg['group_id'] = (int)$msg['group_id'];

        $groupOnlineUsers = $this->groupUserRepo->rGetGroupOnlineUsers($msg['group_id']);
        if (!empty($groupOnlineUsers)) {
            foreach ($groupOnlineUsers as $groupUser) {
                Helper::push(
                    $server,
                    (int)$groupUser['fd'],
                    MsgPayload::make(MsgType::UnpinChatCast, ['pin_chat_id' => $msg['chat_id']])
                );//通知群内用户取消置顶.
            }
        }
    }

    /**
     * 广播通知群内有新讯息.
     *
     * @param \Swoole\Server $server
     * @param array $chat
     *
     * @return void
     */
    public function notifyGroupNewChat(mixed $server, array $chat): void
    {
        if (empty($chat['group_id'])) {
            Log::error(sprintf('缺少group_id: %s', json_encode($chat, 256)), 'notifyNewChatOfGroup_err');

            return;
        }

        /** @var \App\Service\Impl\ChatService $chatService */
        $chatService = Container::get(ChatService::class);
        $newChat = $chatService->decorateNewChat($chat);
        $groupOnlineUsers = $this->groupUserRepo->rGetGroupOnlineUsers($chat['group_id']);
        if (empty($groupOnlineUsers)) {
            Log::info('群内没有在线用户, 中止广播!', 'notifyNewChatOfGroup_skip');

            return;
        }

        $groupOnlineUsers = $this->groupUserRepo->rGetGroupOnlineUsers($chat['group_id']);
        if (!empty($groupOnlineUsers)) {
            foreach ($groupOnlineUsers as $userId => $groupUser) {
                $newChat['is_client'] = (int)$userId === (int)$newChat['user']['id'] ? 1 : 0;
                Helper::push($server, (int)$groupUser['fd'], MsgPayload::make(MsgType::CastChat, $newChat));
            }
        }
    }

    /**
     * 通知系統紅包關閉
     *
     * @param mixed $server
     * @param array $msg
     *
     * @return void
     */
    public function notifySysLmClose(mixed $server, array $msg): void
    {
        if (empty($msg['site_bid']) || empty($msg['lm_id']) || empty($msg['close_type'])) {
            Log::error('参数错误! : ' . json_encode($msg, 256), 'notifySysLmClose_err');

            return;
        }

        /** @var SysLmUserRepository $sysLmUserRepo */
        $sysLmUserRepo = Container::get(SysLmUserRepository::class);
        $lmUsers = $sysLmUserRepo->getLmUsers($msg['lm_id']);
        if (empty($lmUsers)) {
            Log::info('查无系统红包用户, 略过通知');

            return;
        }
        $lmUserMap = array_column($lmUsers, null, 'user_id');

        /** @var SessionRepository $sessionRepo */
        $sessionRepo = Container::get(SessionRepository::class);
        $siteOnlineUsers = $sessionRepo->rGetSiteOnlineAll($msg['site_bid']);
        if (empty($siteOnlineUsers)) {
            Log::info(
                sprintf('当前 %s 站点无人在线, 略过系统红包关闭通知', $msg['site_bid']),
                'notifySysLmClose_cancel'
            );

            return;
        }

        /** @var SysChatRepository $sysChatRepo */
        $sysChatRepo = Container::get(SysChatRepository::class);
        $chat = $sysChatRepo->getByLmId($msg['lm_id']);
        if (empty($chat)) {
            Log::info('通知系统紅包关闭略过, 未找到對應訊息' . json_encode($msg, 256), 'notifySysLmClose_err');

            return;
        }

        $notifyData = [
            'chat' => [
                'id' => (int)$chat['id'],
                'lm_id' => (int)$msg['lm_id'],
            ],
            'close_type' => (int)$msg['close_type'],
        ];

        foreach ($siteOnlineUsers as $onlineUser) {
            $match = $lmUserMap[$onlineUser['user_id']] ?? [];
            if (!empty($match)) {
                $userFd = $sessionRepo->rGetExistsUserFd($match->user_id);
                if ($userFd > 0) {
                    Helper::push($server, $userFd, MsgPayload::make(MsgType::SysLmClosed, $notifyData));
                }
            }
        }
    }

    /**
     * 通知所有连线即将关闭.
     *
     * @param \Swoole\Server $server
     * @param array $msg
     *
     * @return void
     */
    public function notifyServiceClose(mixed $server, array $msg): void
    {
        /** @var ConfigRepository $configRepo */
        $configRepo = Container::get(ConfigRepository::class);
        $closeMsg = trim($configRepo->getByKey(ConfigKey::ServerCloseMsg));
        if (empty($closeMsg)) {
            $closeMsg = '服务即将关闭';
        }

        $payLoad = MsgPayload::make(MsgType::ServiceClose, ['msg' => $closeMsg]);
        foreach ($server->connections as $fd) {
            Helper::disconnect($server, $fd, $payLoad);
        }
    }

    /**
     * 通知聊天列表最新讯息.
     *
     * @param mixed $server
     * @param array $msg
     *
     * @return void
     */
    public function notifyMyGroupLastChat(mixed $server, array $msg): void
    {
        if (empty($msg['group_id'])) {
            Log::error('通知聊天列表用户刷新最新讯息参数错误', 'notifyMyGroupLastChat_err');

            return;
        }

        /** @var SceneRepository $sceneRepo */
        $sceneRepo = Container::get(SceneRepository::class);
        $inSceneUserIds = $sceneRepo->getMyGroupUsers();
        if (empty($inSceneUserIds)) {
            Log::info('当前无人在聊天列表, 略过通知', 'notifyMyGroupLastChat_skip');

            return;
        }

        /** @var GroupUserRepository $groupUserRepo */
        $groupUserRepo = Container::get(GroupUserRepository::class);
        $users = $groupUserRepo->getGroupUsers((int)$msg['group_id'], $inSceneUserIds, ['user_id']);
        if (empty($users)) {
            Log::info(
                '通知聊天列表用户刷新最新讯息, 当前用户无人属于该群组, 略过通知. 群ID:' . $msg['group_id'],
                'notifyMyGroupLastChat_skip2'
            );

            return;
        }

        /** @var SessionRepository $sessionRepo */
        $sessionRepo = Container::get(SessionRepository::class);

        $data = [
            'group_id' => (int)$msg['group_id'],
            'chat_id' => (int)$msg['id'],
            'content' => $msg['content'],
            'time' => date('m-d H:i', $msg['create_time']),
            'create_time' => (int)$msg['create_time'],
            'last_read_add' => 1,
            'type' => (int)$msg['type'],
        ];

        $payLoad = MsgPayload::make(MsgType::MyGroupLastChat, $data);
        foreach ($users as $user) {
            $fd = $sessionRepo->rGetExistsUserFd($user->user_id);
            if ($fd > 0) {
                Helper::push($server, $fd, $payLoad);
            }
        }
    }

    /**
     * 即时通知群内用户状态更新
     *
     * @param mixed $server
     * @param mixed $msg
     *
     * @return void
     */
    public function notifyInGroupRoleChange(mixed $server, array $msg): void
    {
        if (!isset($msg['group_id'], $msg['user_id'], $msg['role_type'], $msg['user_level'], $msg['title'])) {
            Log::error('通知群内用户身份变更失败! 参数错误! :' . json_encode($msg, 256), 'notifyInGroupRoleChange_err');
        }

        /** @var GroupUserRepository $groupUserRepo */
        $groupUserRepo = Container::get(GroupUserRepository::class);
        $onlineUsers = $groupUserRepo->rGetGroupOnlineUsers($msg['group_id']);
        if (empty($onlineUsers)) {
            Log::info('通知群内用户身份变更中止, 无人在线', 'notifyInGroupRoleChange_skip');

            return;
        }

        $userData = [
            'group_id' => (int)$msg['group_id'],
            'user_id' => (int)$msg['user_id'],
            'role_type' => (int)$msg['role_type'],
            'level' => (int)$msg['user_level'],
            'title' => $msg['title'],
        ];
        $payLoad = MsgPayload::make(MsgType::UserRoleChange, $userData);
        foreach ($onlineUsers as $userId => $info) {
            $fd = isset($info['fd']) ? (int)$info['fd'] : 0;
            Helper::push($server, $fd, $payLoad);
        }
    }

}