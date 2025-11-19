<?php

namespace App\Lib\Interface;

use App\Lib\MsgPayload;
use Swoole\WebSocket\Server;

/**
 * 事件处理介面, 根据每个client传递的MessageType进行对应处理.
 */
interface MessageHandlerInterface {

    /**
     * 返回该class对应Message Type
     * @return string
     */
    public function getMsgType(): string;

    /**
     * @param  \Swoole\WebSocket\Server  $server
     * @param  int                       $fd
     * @param  \App\Lib\MsgPayload       $payload
     *
     * @return void
     */
    public function handle(Server $server, int $fd, MsgPayload $payload): void;
}