<?php

namespace App\WebSocket;

use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Helper;
use App\Lib\MsgPayload;
use App\Repository\Impl\ConfigRepository;
use Hyperf\Context\ApplicationContext;
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

class ChatWebSocket
{

    public function onConnect(Server $server, int $fd): void {}

    public function onOpen(Server $server, Request $request): void
    {
        /** @var ConfigRepository $configRepo */
        $configRepo = Container::get(ConfigRepository::class);
        if ( ! $configRepo->isServerOpen()) {
            Helper::disconnect($server, (int)$request->fd, MsgPayload::error(ErrorCode::ErrServiceClosed, null, '服务关闭中'));
        }
    }

    public function onMessage(Server $server, Frame $frame): void
    {
        try {
            $dispatcher = ApplicationContext::getContainer()->get(\App\WebSocket\MessageDispatcher::class);
            $dispatcher->dispatch($server, $frame->fd, $frame->data);
        } catch (\Throwable $e) {
            Log::error(sprintf('消息委派处理失败! 讯息: %s', Helper::getExpDetails($e)), 'onMessage_err');
        }
    }

    public function onShutdown(): void {}

    public function onClose(Server $server, int $fd): void {}

}
