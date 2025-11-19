<?php

namespace App\WebSocket\Handler;

use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Facade\Validator;
use App\Lib\Helper;
use App\Lib\Interface\MessageHandlerInterface;
use App\Lib\MsgPayload;
use App\Lib\MsgType;
use App\Lib\RStream;
use App\Lib\Scene;
use App\Model\ChatRecord;
use App\Model\GroupUser;
use App\Repository\Impl\GroupUserRepository;
use App\Service\Impl\ChatService;
use Hyperf\Context\Context;
use Swoole\WebSocket\Server;

/**
 * 处理管理者删除聊天讯息行为.
 */
class DelChatHandler implements MessageHandlerInterface
{

    public function getMsgType(): string
    {
        return MsgType::DelChat;
    }

    public function handle(Server $server, int $fd, MsgPayload $payload): void
    {
        try {
            $session = Context::get('session');
            $vResult = Validator::validate($payload->data, ['chat_id' => 'required|integer|min:1']);
            if ( ! $vResult->success) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrInvalidParam, $payload));

                return;
            }

            /** @var ChatService $chatService */
            $chatService = Container::get(ChatService::class);
            $chat        = $chatService->getChatById($payload->data['chat_id']);
            if (empty($chat)) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrChatNotExists, $payload, '讯息不存在'));

                return;
            }

            if ((int)$chat['deleted'] === ChatRecord::DeletedYes) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrInvalidOperate, $payload, '该讯息已删除'));
            }

            $groupId = Scene::fetchGroupId($session);
            if (empty($groupId)) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrWrongOperateScene, $payload, "用户不在群内"));

                return;
            }
            /** @var GroupUserRepository $groupUserRepo */
            $groupUserRepo = Container::get(GroupUserRepository::class);
            $groupUser     = $groupUserRepo->getGroupUser($session['id'], $groupId);
            if (empty($groupUser)) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrNotBelongsToGroup, $payload));

                return;
            }

            if ($groupUser['role_type'] === GroupUser::RoleUser) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrInvalidOperate, $payload));

                return;
            }

            $ok = $chatService->softDelChat($chat['id']);
            if ( ! $ok) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrDelChatFailed, $payload));

                return;
            }
            Helper::push($server, $fd, MsgPayload::make(MsgType::DelChatOK, ['chat_id' => $chat['id']], $payload->getMeta()));
            $ok = RStream::push(RStream::TypeNotifyDelChat, ['chat_id' => $chat['id'], 'group_id' => $chat['group_id']]);
            if ( ! $ok) {
                Log::error(
                    sprintf('删除讯息推送进管道失败! group_id:%s, chat_id:%s', $groupId, $chat['id']),
                    'del_chat_push_stream_fail',
                );
            }
        } catch (\Throwable $e) {
            Log::error(sprintf('删除讯息异常! payload: %s, 讯息: %s', $payload->jsonSerialize(), Helper::getExpDetails($e)), 'del_chat_err');
            Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrDelChatFailed, $payload));
        }
    }

}