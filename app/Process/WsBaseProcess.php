<?php

namespace App\Process;

use Hyperf\Process\AbstractProcess;
use Hyperf\WebSocketServer\Server;
use Psr\Container\ContainerInterface;

/**
 * 带有初始化websocket server的base process, 如果process中需要处理ws连线就继承这个, 没有就直接继承AbstractProcess即可
 */
abstract class WsBaseProcess extends AbstractProcess {
    protected Server $websocketServer;
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->websocketServer = $container->get(Server::class);
    }
}