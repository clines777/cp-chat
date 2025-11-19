<?php

namespace App\WebSocket\Handler;

use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Helper;
use App\Lib\Interface\MessageHandlerInterface;
use App\Lib\MsgPayload;
use App\Lib\MsgType;
use App\Lib\RStream;
use App\Lib\Scene;
use App\Model\GroupUser;
use App\Repository\Impl\GroupRepository;
use App\Repository\Impl\GroupUserRepository;
use App\Service\Impl\ChatService;
use Hyperf\Context\Context;
use Swoole\WebSocket\Server;

/**
 * 处理Pin聊天讯息.
 */
class PinChatHandler implements MessageHandlerInterface
{

    public function getMsgType(): string
    {
        return MsgType::PinChat;
    }

    public function handle(Server $server, int $fd, MsgPayload $payload): void
    {
        try {
            if (empty($payload->data['chat_id'])) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrInvalidParam, $payload, '缺少chat_id参数'));

                return;
            }
            $payload->data['chat_id'] = (int)$payload->data['chat_id'];

            $session = Context::get('session');
            if (empty(Scene::fetchGroupId($session))) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrWrongOperateScene, $payload));

                return;
            }

            /** @var ChatService $chatService */
            $chatService = Container::get(ChatService::class);
            $chat        = $chatService->getChatById($payload->data['chat_id']);
            if (empty($chat)) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrChatNotExists, $payload, '查无该讯息'));

                return;
            }

            /** @var GroupRepository $groupRepo */
            $groupRepo = Container::get(GroupRepository::class);
            $groupId   = Scene::fetchGroupId($session);
            $group     = $groupRepo->getById($groupId);
            if (empty($group)) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrGroupNotExists, $payload, '未找到该群组'));

                return;
            }

            if ((int)$chat['group_id'] !== (int)$group['id']) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrInvalidOperate, $payload, '提交消息不属于该群'));

                return;
            }

            /** @var GroupUserRepository $groupUserRepo */
            $groupUserRepo = Container::get(GroupUserRepository::class);
            $groupUser     = $groupUserRepo->getGroupUser($session['id'], $chat['group_id']);
            if (empty($groupUser)) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrNotBelongsToGroup, $payload, '管理者不属于该群'));

                return;
            }

            if ((int)$groupUser['role_type'] === GroupUser::RoleUser) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrInvalidOperate, $payload, '没有操作权限'));

                return;
            }

            if ((int)$group['pin_chat_id'] !== (int)$chat['id']) {
                $ok = $groupRepo->setPinChat($group['id'], $chat['id']);
                if ( ! $ok) {
                    Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrPinChatFailed, $payload, '操作失败'));

                    return;
                }
            }

            $pinChat = $chatService->formatPinChat($chat);
            Helper::push($server, $fd, MsgPayload::make(MsgType::PinChatOK, ['chat_id' => (int)$chat['id']], $payload->getMeta()));
            RStream::push(RStream::TypeNotifyPinChat, $pinChat);
        } catch (\Throwable $e) {
            Log::error(sprintf('讯息置顶异常! payload: %s, 讯息: %s', $payload->jsonSerialize(), Helper::getExpDetails($e)), 'pin_chat_err');
            Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrPinChatFailed, $payload));
        }
    }

}