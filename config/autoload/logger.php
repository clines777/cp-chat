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
return [
    'sql'     => [ //测试用纪录SQL Query历程.
        'handler'   => [
            'class'       => \Monolog\Handler\StreamHandler::class,
            'constructor' => [
                'stream' => BASE_PATH.'/runtime/logs/sql.log',
                'level'  => Monolog\Level::Info,
            ],
        ],
        'formatter' => [
            'class'       => Monolog\Formatter\LineFormatter::class,
            'constructor' => [
                "[%datetime%] [%extra.ip%][%extra.session_id%][%level_name%][%channel%] %message% %context%\n",
                'Y-m-d H:i:s', // datetime format
                true,          // allowInlineLineBreaks
                true,          // ignoreEmptyContextAndExtra
            ],
        ],
    ],
    'default' => [
        'handler'   => [
            'class'       => \Monolog\Handler\StreamHandler::class,
            'constructor' => [
                'stream' => BASE_PATH.'/runtime/logs/logs.log',
                'level'  => Monolog\Level::Info,
            ],
        ],
        'formatter' => [
            'class'       => Monolog\Formatter\LineFormatter::class,
            'constructor' => [
                "[%datetime%] [%extra.ip%][%extra.session_id%][%level_name%][%channel%] %message% %context%\n",
                'Y-m-d H:i:s', // datetime format
                true,          // allowInlineLineBreaks
                true,          // ignoreEmptyContextAndExtra
            ],
        ],
    ],
];
