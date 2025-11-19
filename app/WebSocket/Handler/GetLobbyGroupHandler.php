<?php

namespace App\WebSocket\Handler;

use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Helper;
use App\Lib\Interface\MessageHandlerInterface;
use App\Lib\MsgPayload;
use App\Lib\MsgType;
use App\Service\Impl\GroupService;
use Hyperf\Context\Context;
use Swoole\WebSocket\Server;

class GetLobbyGroupHandler implements MessageHandlerInterface
{

    public function getMsgType(): string
    {
        return MsgType::GetLobbyGroup;
    }

    public function handle(Server $server, int $fd, MsgPayload $payload): void
    {
        $session = Context::get('session');

        /** @var GroupService $groupService */
        $groupService = Container::get(GroupService::class);

        try {
            $lobbyInfo = $groupService->getLobbyInfo($session, $payload->data);
            Helper::push($server, $fd, MsgPayload::make(MsgType::GetLobbyGroupOK, $lobbyInfo, $payload->getMeta()));
        } catch (\Throwable $e) {
            Log::error(sprintf('获取大厅群列表异常! payload: %s, 讯息: %s', $payload->jsonSerialize(), Helper::getExpDetails($e)), 'get_lobby_err');
            Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrGetLobbyGroupFailed, $payload, '获取失败'));
        }
    }

}