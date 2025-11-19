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
use function Hyperf\Support\env;

return [
    'scan' => [
        'paths'              => [
            BASE_PATH.'/app',
        ],
        'ignore_annotations' => [
            'mixin',
        ],
        'cacheable'          => env('SCAN_CACHEABLE', false), // 开发期可设 false 便于热更
    ],
];
