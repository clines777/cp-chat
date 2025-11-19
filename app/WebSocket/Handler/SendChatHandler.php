<?php

namespace App\WebSocket\Handler;

use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Facade\Redis;
use App\Lib\Helper;
use App\Lib\Interface\MessageHandlerInterface;
use App\Lib\MsgPayload;
use App\Lib\MsgType;
use App\Lib\RStream;
use App\Lib\Scene;
use App\Model\ChatRecord;
use App\Model\Group;
use App\Model\GroupUser;
use App\Model\User;
use App\Repository\Impl\GroupRepository;
use App\Repository\Impl\GroupUserRepository;
use App\Repository\Impl\UserRepository;
use App\Service\Impl\ChatService;
use Hyperf\Context\Context;
use Swoole\WebSocket\Server;

/**
 * 处理client发送聊天讯息.
 */
class SendChatHandler implements MessageHandlerInterface
{

    /**
     * @param  \Swoole\WebSocket\Server  $server
     * @param  int                       $fd
     * @param  \App\Lib\MsgPayload       $payload
     *
     * @return void
     */
    public function handle(Server $server, int $fd, MsgPayload $payload): void
    {
        $session = Context::get('session');

        $timeLock = sprintf('send_chat_lock_%s', $fd);
        if ( ! Redis::lock($timeLock, 1)) {
            Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrSendChatInterval, $payload, '发言频率过高。'));
        }

        if ( ! isset($payload->data['content']) || empty(trim($payload->data['content']))) {
            Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrSendChatEmpty, $payload, '发送讯息为空'));
        }

        try {
            $groupId = Scene::fetchGroupId($session);
            if (empty($groupId)) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrGroupNotExists, $payload, '无效的群'));

                return;
            }

            /** @var GroupRepository $groupRepo */
            $groupRepo = Container::get(GroupRepository::class);
            $group     = $groupRepo->getById($groupId);
            if (empty($group)) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrGroupNotExists, $payload, '无效的群组'));

                return;
            }

            if ((int)$group['is_dismiss'] === Group::DismissYes) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrGroupDismissed, $payload, '群组已解散'));

                return;
            }

            /** @var GroupUserRepository $groupUserRepo */
            $groupUserRepo = Container::get(GroupUserRepository::class);

            $groupUser = $groupUserRepo->getJoinGroup(
                $groupId,
                $session['id'],
                ['group.site_bid', 'group_user.is_ban', 'group.speak_user_level', 'group_user.role_type'],
            );
            if (empty($groupUser)) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrNotBelongsToGroup, $payload));

                return;
            }

            if (isset($session['user_level']) && $session['user_level'] < $groupUser['speak_user_level']) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrUserBlockBySpeakLevel, $payload, '发言等级不足'));

                return;
            }

            /** @var UserRepository $userRepo */
            $userRepo = Container::get(UserRepository::class);
            $user     = $userRepo->getOne($session['id'], ['is_global_ban']);
            if ((int)$user['is_global_ban'] === User::IsGlobalBanYes) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrUserGlobalBanned, $payload, '用户已被全域禁言'));

                return;
            }

            if ((int)$groupUser['is_ban'] === GroupUser::BanYes) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrUserBanned, $payload, '用户已被禁言'));

                return;
            }

            $onlineInfo = $groupUserRepo->rGetGroupOnlineUser($groupId, $session['id']);
            if (empty($onlineInfo)) {
                Log::error(sprintf("SendChatHandler, 未查到onlineInfo, fd: %s, payload: %s", $fd, $payload->jsonSerialize()), 'sendChatHandler');
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrWrongOperateScene, $payload));

                return;
            }

            //判断是否阻挡一般群内成员输入网址, 这功能是预作, 目前后台没地方设定, 直接改group table的栏位
            $allowUrl = isset($group['allow_url']) && $group['allow_url'] > 0;
            //对管理者以上开放权限
            if ($groupUser['role_type'] > GroupUser::RoleUser) {
                $allowUrl = true;
            }

            /** @var ChatService $chatService */
            $chatService = Container::get(ChatService::class);
            $result      = $chatService->addChatMessage($session['id'], $session['site_bid'], $groupId, $payload->data, ChatRecord::TypeNormal, $allowUrl);
            if ( ! $result->isSuccess()) {
                Log::error(sprintf('处理用户提交聊天讯息失败! 原始msg: %s, 错误: %s', $payload->jsonSerialize(), $result->msg), 'sendChatHandler_err');
                Helper::push($server, $fd, MsgPayload::error($result->code, $payload, $result->msg));
            } else {
                Helper::push($server, $fd, MsgPayload::make(MsgType::SendChatOK, ['chat_id' => (int)$result->data['id']], $payload->getMeta()));
                RStream::push(RStream::TypeNotifyInGroupNewChat, $result->data);
                RStream::push(RStream::TypeNotifyMyGroupLastChat, $result->data);
            }
        } catch (\Throwable $e) {
            Log::error(sprintf('处理用户提交聊天讯息异常! 讯息: %s', Helper::getExpDetails($e)), 'sendChatHandler_err');
            Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrSendChatFailed, $payload, '聊天消息提交失败'));
        }
    }

    public function getMsgType(): string
    {
        return MsgType::SendChat;
    }

}