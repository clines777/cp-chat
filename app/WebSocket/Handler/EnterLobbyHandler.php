<?php

namespace App\WebSocket\Handler;

use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Helper;
use App\Lib\Interface\MessageHandlerInterface;
use App\Lib\MsgPayload;
use App\Lib\MsgType;
use App\Lib\Scene;
use App\Repository\Impl\SceneRepository;
use App\Repository\Impl\SessionRepository;
use App\Service\Impl\GroupService;
use Swoole\WebSocket\Server;

/**
 * 进入大厅.
 */
class EnterLobbyHandler implements MessageHandlerInterface
{

    public function getMsgType(): string
    {
        return MsgType::EnterLobby;
    }

    public function handle(Server $server, int $fd, MsgPayload $payload): void
    {
        /** @var SessionRepository $sessionRepo */
        $sessionRepo = Container::get(SessionRepository::class);

        /** @var GroupService $groupService */
        $groupService = Container::get(GroupService::class);

        try {
            $session   = $sessionRepo->rSetScene($fd, Scene::Lobby);
            $lobbyInfo = $groupService->getLobbyInfo($session, $payload->data);

            /** @var SceneRepository $sceneRepo */
            $sceneRepo = Container::get(SceneRepository::class);
            if ($sceneRepo->existsAtMyGroup($session['id'])) {
                $sceneRepo->removeAtMyGroup($session['id']);
            }

            if ( ! $sceneRepo->existsAtLobby($session['id'])) {
                $sceneRepo->addAtLobby($session['id']);
            }

            Helper::push($server, $fd, MsgPayload::make(MsgType::EnterLobbyOK, $lobbyInfo, $payload->getMeta()));
        } catch (\Throwable $e) {
            Log::error(sprintf('进入大厅群异常! payload: %s, 讯息: %s', $payload->jsonSerialize(), Helper::getExpDetails($e)), 'enterLobby_err');
            Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrEnterLobbyFailed, $payload, '进入大厅失败'));
        }
    }

}