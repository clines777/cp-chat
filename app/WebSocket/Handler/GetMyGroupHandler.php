<?php

namespace App\WebSocket\Handler;

use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Helper;
use App\Lib\Interface\MessageHandlerInterface;
use App\Lib\MsgPayload;
use App\Lib\MsgType;
use App\Service\Impl\GroupUserService;
use App\Service\Impl\SysChatService;
use Hyperf\Context\Context;
use Swoole\WebSocket\Server;

class GetMyGroupHandler implements MessageHandlerInterface
{

    public function getMsgType(): string
    {
        return MsgType::GetMyGroup;
    }

    public function handle(Server $server, int $fd, MsgPayload $payload): void
    {
        $session = Context::get('session');

        /** @var GroupUserService $groupUserService */
        $groupUserService = Container::get(GroupUserService::class);

        /** @var SysChatService $sysChatService */
        $sysChatService = Container::get(SysChatService::class);

        try {
            $sysGroupInfo = $sysChatService->getSysChatPreview($session['id']);
            $myGroups     = $groupUserService->getUserGroupList($session['id'], $payload->data);
            Helper::push($server, $fd, MsgPayload::make(MsgType::GetMyGroupOK, ['my_group' => $myGroups, 'sys_group' => $sysGroupInfo], $payload->getMeta()));
        } catch (\Throwable $e) {
            Log::error(sprintf('ws获取我的聊天群异常! payload: %s, 讯息: %s', $payload->jsonSerialize(), Helper::getExpDetails($e)));
            Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrGetMyGroupFailed, $payload, '获取失败'));
        }
    }

}