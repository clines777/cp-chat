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
use App\Service\Impl\GroupUserService;
use App\Service\Impl\SysChatService;
use Hyperf\Context\Context;
use Swoole\WebSocket\Server;

class EnterMyGroupHandler implements MessageHandlerInterface
{

    public function getMsgType(): string
    {
        return MsgType::EnterMyGroup;
    }

    public function handle(Server $server, int $fd, MsgPayload $payload): void
    {
        $session = Context::get('session');
        try {
            /** @var SessionRepository $sessionRepo */
            $sessionRepo = Container::get(SessionRepository::class);
            $sessionRepo->rSetScene($fd, Scene::MyGroup);

            /** @var SysChatService $sysChatService */
            $sysChatService = Container::get(SysChatService::class);
            $sysGroupInfo   = $sysChatService->getSysChatPreview($session['id']);

            /** @var GroupUserService $groupUserService */
            $groupUserService = Container::get(GroupUserService::class);
            $groupInfo        = $groupUserService->getUserGroupList($session['id'], $payload->data);

            /** @var SceneRepository $sceneRepo */
            $sceneRepo = Container::get(SceneRepository::class);
            if ( ! $sceneRepo->existsAtMyGroup($session['id'])) {
                $sceneRepo->addAtMyGroup($session['id']);
            }

            Helper::push($server, $fd, MsgPayload::make(MsgType::EnterMyGroupOK, ['my_group' => $groupInfo, 'sys_group' => $sysGroupInfo], $payload->getMeta()));
        } catch (\Throwable $e) {
            Log::error(sprintf("进入聊天列表异常! 原始数据: %s, 讯息: %s", $payload->jsonSerialize(), Helper::getExpDetails($e)), 'enter_my_group_err');
            Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrEnterMyGroupFailed, $payload, '进入失败'));
        }
    }

}