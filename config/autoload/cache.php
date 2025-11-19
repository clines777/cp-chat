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
    'default' => [
        'driver'             => Hyperf\Cache\Driver\RedisDriver::class,
        'packer'             => Hyperf\Codec\Packer\PhpSerializerPacker::class,
        'prefix'             => '',
        'skip_cache_results' => [],
        'options'            => [
            'pool' => 'cache',//redis pool, 详情查看config/autoload/redis.php
        ],
    ],
];
