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
use App\Repository\Impl\GroupUserRepository;
use App\Repository\Impl\SessionRepository;
use Hyperf\Context\Context;
use Swoole\WebSocket\Server;

/**
 * 退出聊天群
 */
class LeaveGroupHandler implements MessageHandlerInterface
{

    public function getMsgType(): string
    {
        return MsgType::LeaveGroup;
    }

    public function handle(Server $server, int $fd, MsgPayload $payload): void
    {
        $session = Context::get('session');
        try {
            /** @var GroupUserRepository $groupUserRepo */
            $groupUserRepo = Container::get(GroupUserRepository::class);
            $groupId       = Scene::fetchGroupId($session);
            if ($groupId > 0) {
                $groupUserRepo->rDelOnlineUser($groupId, $session['id']);
                $userCount = $groupUserRepo->countGroupUser($groupId);
                RStream::push(RStream::TypeNotifyGroupState, ['group_id' => $groupId, 'user_count' => $userCount]);//通知更新群在线人数.
            }

            /** @var SessionRepository $sessionRepo */
            $sessionRepo = Container::get(SessionRepository::class);
            $sessionRepo->rSetScene($fd, Scene::MyGroup);

            Helper::push($server, $fd, MsgPayload::make(MsgType::LeaveGroupOK, [], $payload->getMeta()));
        } catch (\Throwable $e) {
            Log::error(sprintf("用户离开群组异常! payload: %s 讯息: %s", $payload->jsonSerialize(), Helper::getExpDetails($e)), 'leaveGroup_err');
            Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrLeaveGroupFailed, $payload));
        }
    }

}