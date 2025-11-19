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

use Hyperf\Contract\PackerInterface;
use Swoole\WebSocket\Server as WebSocketServer;

//redis stream process用的注入
return [
    WebSocketServer::class => function () {
        /** @var \Hyperf\Server\ServerFactory $factory */
        $factory = \Hyperf\Context\ApplicationContext::getContainer()->get(\Hyperf\Server\ServerFactory::class);

        return $factory->getServer('ws');//
    },
    PackerInterface::class => \Hyperf\Codec\Packer\PhpSerializerPacker::class,
];
