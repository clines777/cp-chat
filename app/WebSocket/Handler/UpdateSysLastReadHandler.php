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

/**
 * 更新系统消息最新已读
 */
class UpdateSysLastReadHandler implements MessageHandlerInterface
{

    public function getMsgType(): string
    {
        return MsgType::UpdateSysLastRead;
    }

    public function handle(Server $server, int $fd, MsgPayload $payload): void
    {
        $session = Context::get('session');
        try {
            /** @var SysChatService $sysChatService */
            $sysChatService = Container::get(SysChatService::class);

            if (empty($payload->data['chat_id'])) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrInvalidParam, $payload, '参数错误'));

                return;
            }

            $result = $sysChatService->setLastRead($session, $fd, $payload->data);
            if ( ! $result->isSuccess()) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrUpdateSysLastRead, $payload, $result->msg));

                return;
            }

            Helper::push($server, $fd, MsgPayload::make(MsgType::UpdateSysLastReadOK, ['chat_id' => (int)$payload->data['chat_id']], $payload->getMeta()));
        } catch (\Throwable $e) {
            Log::error(sprintf('更新系统消息已读异常! 参数: %s, 讯息: %s', $payload->jsonSerialize(), Helper::getExpDetails($e)), 'updateSysLastRead_err');
            Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrUpdateSysLastReadFailed, $payload, '更新系统群已读失败'));
        }
    }

}