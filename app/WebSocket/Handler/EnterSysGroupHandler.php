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
use App\Repository\Impl\UserRepository;
use App\Service\Impl\SysChatService;
use Hyperf\Context\Context;
use Swoole\WebSocket\Server;

/**
 * 進入系統頻道
 */
class EnterSysGroupHandler implements MessageHandlerInterface
{

    public function getMsgType(): string
    {
        return MsgType::EnterSysGroup;
    }

    public function handle(Server $server, int $fd, MsgPayload $payload): void
    {
        $session = Context::get('session');
        try {
            /** @var SysChatService $sysChatService */
            $sysChatService = Container::get(SysChatService::class);

            /** @var SessionRepository $sessionRepo */
            $sessionRepo = Container::get(SessionRepository::class);
            $sessionRepo->rSetScene($fd, Scene::SysGroup);
            $group    = ['title' => '系统消息'];
            $chatList = $sysChatService->getEnterSysGroupInitInfo($session);

            /** @var SceneRepository $sceneRepo */
            $sceneRepo = Container::get(SceneRepository::class);
            if ($sceneRepo->existsAtLobby($session['id'])) {
                $sceneRepo->removeAtLobby($session['id']);
            }

            if ($sceneRepo->existsAtMyGroup($session['id'])) {
                $sceneRepo->removeAtMyGroup($session['id']);
            }

            /** @var UserRepository $userRepo */
            $userRepo = Container::get(UserRepository::class);
            $user     = $userRepo->getOne($session['id'], ['sys_chat_last_read_id']);
            Helper::push(
                $server,
                $fd,
                MsgPayload::make(
                    MsgType::EnterSysGroupOK,
                    ['group' => $group, 'chat_list' => $chatList, 'last_read_id' => (int)$user['sys_chat_last_read_id']],
                    $payload->getMeta(),
                ),
            );
        } catch (\Throwable $e) {
            Log::error(sprintf('獲取進入系統群初始數據異常! Session: %s, 訊息: %s', json_encode($session, 256), Helper::getExpDetails($e)), 'enter_sys_group_err');
            Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrEnterSysGroupFailed, $payload, '進入系統群失敗'));
        }
    }

}