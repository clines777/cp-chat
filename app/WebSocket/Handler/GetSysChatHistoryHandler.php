<?php

namespace App\WebSocket\Handler;

use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Helper;
use App\Lib\Interface\MessageHandlerInterface;
use App\Lib\MsgPayload;
use App\Lib\MsgType;
use App\Service\Impl\SysChatService;
use Hyperf\Context\Context;
use Swoole\WebSocket\Server;

class GetSysChatHistoryHandler implements MessageHandlerInterface
{

    public function getMsgType(): string
    {
        return MsgType::GetSysChatHistory;
    }

    public function handle(Server $server, int $fd, MsgPayload $payload): void
    {
        $session = Context::get('session');

        try {
            /** @var SysChatService $sysChatService */
            $sysChatService = Container::get(SysChatService::class);
            $result         = $sysChatService->getHistory($session, $payload->data);
            if ( ! $result->isSuccess()) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrGetSysChatHistoryFailed, $payload, '获取失败'));

                return;
            }
            Helper::push($server, $fd, MsgPayload::make(MsgType::GetSysChatHistoryOK, $result->data, $payload->getMeta()));
        } catch (\Throwable $e) {
            Log::error(sprintf('ws获取系统群消息异常! 参数: %s, 讯息: %s', $payload->jsonSerialize(), Helper::getExpDetails($e)), 'ws_getSysChatHistory_err');
            Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrGetSysChatHistoryFailed, $payload, '获取失败'));
        }
    }

}