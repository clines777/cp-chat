<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
use App\Lib\DeployConfig;
use Hyperf\Context\ApplicationContext;
use Hyperf\Server\Event;
use Hyperf\Server\Server;
use Swoole\Constant;

$deployConfig = ApplicationContext::getContainer()->get(DeployConfig::class);
$deployConfig->setCurrent(DeployConfig::TypeSever);

$deployConfig->printAll();

return [
    'mode'      => SWOOLE_PROCESS,
    'servers'   => [
        [
            'name'                       => 'http',
            'type'                       => Server::SERVER_HTTP,
            'host'                       => $deployConfig->config('api_host'),
            'port'                       => (int)$deployConfig->config('api_port'),
            'sock_type'                  => SWOOLE_SOCK_TCP,
            'callbacks'                  => [
                Event::ON_REQUEST => [Hyperf\HttpServer\Server::class, 'onRequest'],
            ],
            'options'                    => [
                'enable_request_lifecycle' => false,
            ],
            'max_request_execution_time' => 0,
        ],
        [
            'name'      => 'ws',
            'type'      => Server::SERVER_WEBSOCKET,
            'host'      => $deployConfig->config('ws_host'),
            'port'      => (int)$deployConfig->config('ws_port'),
            'sock_type' => SWOOLE_SOCK_TCP,
            'callbacks' => [//websocket handler对应Handlers需定义在这.
                Event::ON_CONNECT  => [\App\WebSocket\ChatWebSocket::class, 'onConnect'],
                Event::ON_OPEN     => [\App\WebSocket\ChatWebSocket::class, 'onOpen'],
                Event::ON_MESSAGE  => [\App\WebSocket\ChatWebSocket::class, 'onMessage'],
                Event::ON_SHUTDOWN => [\App\WebSocket\ChatWebSocket::class, 'onShutdown'],
                Event::ON_CLOSE    => [\App\WebSocket\ChatWebSocket::class, 'onClose'],
            ],
            'routes'    => [
                '/ws' => \App\WebSocket\ChatWebSocket::class,
            ],
            'settings'  => [
                'max_connection' => $deployConfig->config('ws_max_conn'),
                'worker_num'     => swoole_cpu_num(),
            ],
        ],
    ],
    'settings'  => [
        Constant::OPTION_ENABLE_COROUTINE         => true,
        Constant::OPTION_WORKER_NUM               => swoole_cpu_num(),
        Constant::OPTION_PID_FILE                 => BASE_PATH.'/runtime/hyperf.pid',
        Constant::OPTION_OPEN_TCP_NODELAY         => true,
        Constant::OPTION_MAX_COROUTINE            => 100000,
        Constant::OPTION_OPEN_HTTP2_PROTOCOL      => true,
        Constant::OPTION_MAX_REQUEST              => 100000,
        Constant::OPTION_SOCKET_BUFFER_SIZE       => 2 * 1024 * 1024,
        Constant::OPTION_BUFFER_OUTPUT_SIZE       => 2 * 1024 * 1024,
        Constant::OPTION_MAX_WAIT_TIME            => 10,//收到关闭SIGTERM时最长等待秒数
        Constant::OPTION_RELOAD_ASYNC             => true,//允许优雅关闭
        Constant::OPTION_HEARTBEAT_CHECK_INTERVAL => 300,//swoole内部heartbeat频率
        Constant::OPTION_HEARTBEAT_IDLE_TIME      => 600,//连线最长闲置时间
    ],
    'callbacks' => [
        Event::ON_WORKER_START => [Hyperf\Framework\Bootstrap\WorkerStartCallback::class, 'onWorkerStart'],
        Event::ON_PIPE_MESSAGE => [Hyperf\Framework\Bootstrap\PipeMessageCallback::class, 'onPipeMessage'],
        Event::ON_WORKER_EXIT  => [Hyperf\Framework\Bootstrap\WorkerExitCallback::class, 'onWorkerExit'],
    ],
];
