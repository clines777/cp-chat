<?php

namespace App\WebSocket\Handler;

use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Helper;
use App\Lib\Interface\MessageHandlerInterface;
use App\Lib\MsgPayload;
use App\Lib\MsgType;
use App\Repository\Impl\MarqueeRepository;
use App\Service\Impl\GroupUserService;
use App\Service\Impl\SysChatService;
use App\Service\Impl\UserService;
use Swoole\WebSocket\Server;

/**
 * 登入
 */
class LoginHandler implements MessageHandlerInterface
{

    public function getMsgType(): string
    {
        return MsgType::Login;
    }

    public function handle(Server $server, int $fd, MsgPayload $payload): void
    {
        try {
            /** @var UserService $userService */
            $userService = Container::get(UserService::class);
            $loginResult = $userService->login($server, $fd, $payload);
            if (empty($loginResult->data['user']) || ! $loginResult->isSuccess()) {
                Log::error('用户登入失败! 讯息:'.$loginResult->msg, 'login_err');
                Helper::disconnect($server, $fd, MsgPayload::error(ErrorCode::ErrLoginFailed, $payload, $loginResult->msg));

                return;
            }

            /** @var GroupUserService $groupUserService */
            $groupUserService = Container::get(GroupUserService::class);
            $myGroups         = $groupUserService->getUserGroupList($loginResult->data['user']['id']);

            /** @var SysChatService $sysChatService */
            $sysChatService = Container::get(SysChatService::class);
            $sysGroupInfo   = $sysChatService->getSysChatPreview($loginResult->data['user']['id']);

            $info = ['user' => $loginResult->data['user'], 'my_group' => $myGroups, 'sys_group' => $sysGroupInfo, 'marquee' => []];

            /** @var \App\Repository\Impl\MarqueeRepository $marqueeRepo */
            $marqueeRepo = Container::get(MarqueeRepository::class);
            $marquee     = $marqueeRepo->rGetMarquee();
            if ( ! empty($marquee)) {
                $info['marquee'] = $marquee;
            }

            Helper::push($server, $fd, MsgPayload::make(MsgType::LoginOK, $info, $payload->getMeta()));
        } catch (\Throwable $e) {
            Log::error("用户登入异常! 讯息:".Helper::getExpDetails($e));
            Helper::disconnect($server, $fd, MsgPayload::error(ErrorCode::ErrLoginFailed, $payload));
        }
    }

}