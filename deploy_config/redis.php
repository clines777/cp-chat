<?php

use function Hyperf\Support\env;

return [

    //状态
    'state_host'  => env('REDIS_STATE_HOST', 'localhost'),
    'state_port'  => env('REDIS_STATE_PORT', 6380),
    'state_auth'  => env('REDIS_STATE_AUTH'),
    'state_db'    => env('REDIS_STATE_DB', 1),//状态DB

    //缓存
    'cache_host'  => env('REDIS_CACHE_HOST', 'localhost'),
    'cache_port'  => env('REDIS_CACHE_PORT', 6380),
    'cache_auth'  => env('REDIS_CACHE_AUTH'),
    'cache_db'    => env('REDIS_CACHE_DB', 0),//缓存DB

    //消息队列
    'stream_host' => env('REDIS_STREAM_HOST', 'localhost'),
    'stream_port' => env('REDIS_STREAM_PORT', 6380),
    'stream_auth' => env('REDIS_STREAM_AUTH'),
    'stream_db'   => env('REDIS_STREAM_DB', 2),//消息队列DB

    //红包
    'bonus_host'  => env('REDIS_BONUS_HOST', 'localhost'),
    'bonus_port'  => env('REDIS_BONUS_PORT', 6380),
    'bonus_auth'  => env('REDIS_BONUS_AUTH'),
    'bonus_db'    => env('REDIS_BONUS_DB', 3),//红包DB

    'max_idle_time' => env('REDIS_MAX_IDLE_TIME', 60),
];