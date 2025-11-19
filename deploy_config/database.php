<?php

use function Hyperf\Support\env;

return [
    'enable_log' => env('DB_ENABLE_LOG', false),
    'master'     => [
        'type'      => 'mysql',
        'host'      => env('DB_MASTER_HOST', 'localhost'),
        'port'      => env('DB_MASTER_PORT', '3306'),
        'database'  => env('DB_DATABASE', 'cp_chat'),
        'username'  => env('DB_MASTER_USERNAME', 'root'),
        'password'  => env('DB_MASTER_PASSWORD', 'root'),
        'charset'   => env('DB_CHARSET', 'utf8mb4'),
        'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
        'prefix'    => env('DB_PREFIX', ''),
        'max_idle'  => env('DB_MAX_IDLE_TIME', 60),
    ],
    'slave'      => [
        'type'      => 'mysql',
        'host'      => env('DB_SLAVE_HOST', env('DB_MASTER_HOST')),
        'port'      => env('DB_SLAVE_PORT', env('DB_MASTER_PORT')),
        'database'  => env('DB_DATABASE', env('DB_DATABASE')),
        'username'  => env('DB_SLAVE_USERNAME', env('DB_MASTER_USERNAME')),
        'password'  => env('DB_SLAVE_PASSWORD', env('DB_MASTER_PASSWORD')),
        'charset'   => env('DB_CHARSET', 'utf8mb4'),
        'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
        'prefix'    => env('DB_PREFIX', ''),
        'max_idle'  => env('DB_MAX_IDLE_TIME', 60),
    ],
];