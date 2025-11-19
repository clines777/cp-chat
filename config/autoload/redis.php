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

$deployConfig = ApplicationContext::getContainer()->get(DeployConfig::class);
$deployConfig->setCurrent(DeployConfig::TypeRedis);

$deployConfig->printAll();

return [
    'default' => [
        'host' => $deployConfig->config('state_host'),
        'auth' => $deployConfig->config('state_auth'),
        'port' => (int)$deployConfig->config('state_port'),
        'db'   => (int)$deployConfig->config('state_db'),
        'pool' => [
            'min_connections' => 5,
            'max_connections' => 100,
            'connect_timeout' => 1.0,
            'wait_timeout'    => 1.0,
            'heartbeat'       => 30,
            'max_idle_time'   => 40,
        ],
    ],

    'cache' => [ //cache用
        'host' => $deployConfig->config('cache_host'),
        'auth' => $deployConfig->config('cache_auth'),
        'port' => (int)$deployConfig->config('cache_port'),
        'db'   => (int)$deployConfig->config('cache_db'),
        'pool' => [
            'min_connections' => 5,
            'max_connections' => 100,
            'connect_timeout' => 1.0,
            'wait_timeout'    => 1.0,
            'heartbeat'       => 30,
            'max_idle_time'   => 40,
        ],
    ],

    'notify' => [ //共用通知用Redis Stream
        'host' => $deployConfig->config('stream_host'),
        'auth' => $deployConfig->config('stream_auth'),
        'port' => (int)$deployConfig->config('stream_port'),
        'db'   => (int)$deployConfig->config('stream_db'),
        'pool' => [
            'min_connections' => 2,
            'max_connections' => 60,
            'connect_timeout' => 1.0,
            'wait_timeout'    => 1.2,
            'heartbeat'       => 30,
            'max_idle_time'   => 35,
        ],
    ],
    'batch'  => [ //共用通知用Redis Stream
        'host' => $deployConfig->config('stream_host'),
        'auth' => $deployConfig->config('stream_auth'),
        'port' => (int)$deployConfig->config('stream_port'),
        'db'   => 4,
        'pool' => [
            'min_connections' => 5,
            'max_connections' => 100,
            'connect_timeout' => 1.0,
            'wait_timeout'    => 0.5,
            'heartbeat'       => 30,
            'max_idle_time'   => 40,
        ],
    ],
    'bonus'  => [ //红包
        'host' => $deployConfig->config('bonus_host'),
        'auth' => $deployConfig->config('bonus_auth'),
        'port' => (int)$deployConfig->config('bonus_port'),
        'db'   => (int)$deployConfig->config('bonus_db'),
        'pool' => [
            'min_connections' => 5,
            'max_connections' => 100,
            'connect_timeout' => 1.0,
            'wait_timeout'    => 1.0,
            'heartbeat'       => 30,
            'max_idle_time'   => 40,
        ],
    ],
];
