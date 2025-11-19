<?php

use function Hyperf\Support\env;

return [
    'api_host'    => env('API_HOST', '0.0.0.0'),
    'api_port'    => env('API_PORT', 9501),
    'ws_host'     => env('WS_HOST', '0.0.0.0'),
    'ws_port'     => env('WS_PORT', 9502),
    'ws_max_conn' => env('WS_MAX_CONN', 10000),//Websocket同时最大连线数, 没给的话Swoole默认是10000, 实际最大值受限于机器本身的ulimit
    'server_id'   => env('SERVER_ID', 0),
    //Server Id, 用作WS环境下每条连线session的基底key值(session key=当下连线ID+server_id),
    // 如果多台server设相同会导致各自server偶发取不到自己对应的session数据造成频繁断线,
    // 生产环境目前用机器位址作为server id.
    'app_env'     => env('APP_ENV', 'prod'),
];