<?php

namespace App\WebSocket\Handler;

use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Helper;
use App\Lib\Interface\MessageHandlerInterface;
use App\Lib\MsgPayload;
use App\Lib\MsgType;
use App\Repository\Impl\SessionRepository;
use Swoole\WebSocket\Server;

class PingHandler implements MessageHandlerInterface
{

    public function getMsgType(): string
    {
        return MsgType::Ping;
    }

    public function handle(Server $server, int $fd, MsgPayload $payload): void
    {
        try {
            /** @var SessionRepository $sessionRepo */
            $sessionRepo = Container::get(SessionRepository::class);
            $userId      = $sessionRepo->rRefreshSessionTokens($fd);
            if (empty($userId) || ! $server->isEstablished($fd)) {
                Log::error(sprintf('Ping连线丢失 fd: %d', $fd), 'ping_connection_lost');

                return;
            }
            Helper::push($server, $fd, MsgPayload::make(MsgType::Pong, ['t' => time()], $payload->getMeta()));
        } catch (\Throwable $e) {
            Log::error(sprintf('PingHandler error: %s', Helper::getExpDetails($e)), 'ping_err');
            Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrSessionLost, $payload, 'session刷新失败'));
        }
    }

}