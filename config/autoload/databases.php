<?php

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
$deployConfig->setCurrent(DeployConfig::TypeDatabase);

return [
    'default' => [
        'driver'    => $deployConfig->config('master', 'type'),
        'host'      => $deployConfig->config('master', 'host'),
        'port'      => $deployConfig->config('master', 'port'),
        'database'  => $deployConfig->config('master', 'database'),
        'username'  => $deployConfig->config('master', 'username'),
        'password'  => $deployConfig->config('master', 'password'),
        'charset'   => $deployConfig->config('master', 'charset'),
        'collation' => $deployConfig->config('master', 'collation'),
        'prefix'    => $deployConfig->config('master', 'prefix'),
        'reconnect' => true,
        'pool'      => [
            'min_connections' => 0,
            'max_connections' => 20,
            'connect_timeout' => 10.0,
            'wait_timeout'    => 3.0,
            'heartbeat'       => 30,
            'max_idle_time'   => (float)$deployConfig->config('master', 'max_idle'),
        ],
        'options'   => [
            'ping' => true,//查询时检查是否断线, 断了自动重连
        ],
        'logging'   => [
            'enabled' => $deployConfig->config('enable_log'),
        ],
        'cache'     => [
            'handler'         => Hyperf\Cache\Driver\FileSystemDriver::class,
            'cache_key'       => '{mc:%s:m:%s}:%s:%s',
            'prefix'          => 'default',
            'ttl'             => 3600 * 24,
            'empty_model_ttl' => 600,
            'load_script'     => true,
        ],
        'commands'  => [
            'gen:model' => [
                'path'          => 'app/Model',
                'force_casts'   => true,
                'inheritance'   => 'Model',
                'uses'          => '',
                'table_mapping' => [],
            ],
        ],
    ],
];
