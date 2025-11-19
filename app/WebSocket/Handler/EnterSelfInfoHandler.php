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
use App\Repository\Impl\SessionRepository;
use Hyperf\Context\Context;
use Swoole\WebSocket\Server;

class EnterSelfInfoHandler implements MessageHandlerInterface
{

    public function getMsgType(): string
    {
        return MsgType::EnterSelfInfo;
    }

    public function handle(Server $server, int $fd, MsgPayload $payload): void
    {
        try {
            /** @var SessionRepository $sessionRepo */
            $sessionRepo = Container::get(SessionRepository::class);
            $sessionRepo->rSetScene($fd, Scene::SelfInfo);
            $session = Context::get('session');
            $info    = ['code' => $session['code']];
            Helper::push($server, $fd, MsgPayload::make(MsgType::EnterSelfInfoOK, $info, $payload->getMeta()));
        } catch (\Throwable $e) {
            Log::error(sprintf('进入我的资讯页异常! 讯息: %s', Helper::getExpDetails($e)), 'enter_self_info');
            Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrEnterSelfInfoFailed, $payload, '进入我的资讯页失败'));
        }
    }

}