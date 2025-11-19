<?php

namespace App\WebSocket\Handler;

use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Helper;
use App\Lib\Interface\MessageHandlerInterface;
use App\Lib\MsgPayload;
use App\Lib\MsgType;
use App\Lib\RStream;
use App\Service\Impl\GroupUserService;
use Hyperf\Context\Context;
use Swoole\WebSocket\Server;

/**
 * 更新一般群最新已读.
 */
class UpdateLastReadHandler implements MessageHandlerInterface
{

    public function getMsgType(): string
    {
        return MsgType::UpdateLastRead;
    }

    public function handle(Server $server, int $fd, MsgPayload $payload): void
    {
        $session = Context::get('session');
        try {
            /** @var GroupUserService $groupUserService */
            $groupUserService = Container::get(GroupUserService::class);
            $result           = $groupUserService->setLastRead($session, $payload->data);
            if ( ! $result->isSuccess()) {
                Log::error('更新群内最新已读失败! 讯息:'.$result->msg, 'setLastRead_err');
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrUpdateLastReadFailed, $payload, $result->msg));

                return;
            }

            Helper::push(
                $server,
                $fd,
                MsgPayload::make(
                    MsgType::UpdateLastReadOK,
                    ['chat_id' => (int)$payload->data['chat_id'], 'group_id' => (int)$payload->data['group_id']],
                    $payload->getMeta(),
                ),
            );
            RStream::push(RStream::TypeNotifyMyGroupLastRead, $result->data);
        } catch (\Throwable $e) {
            Log::error(sprintf('更新群最后已读异常! 原始数据: %s, 讯息: %s', $payload->jsonSerialize(), Helper::getExpDetails($e)), 'UpdateLastRead_exp');
            Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrUpdateLastReadFailed));
        }
    }

}