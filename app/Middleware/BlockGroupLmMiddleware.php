<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Lib\CommonResult;
use App\Lib\ErrorCode;
use App\Lib\Helper;
use Hyperf\Context\ApplicationContext;
use Hyperf\HttpServer\Contract\ResponseInterface as HyperfResponse;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class BlockGroupLmMiddleware implements MiddlewareInterface
{

    public function __construct(protected ContainerInterface $container) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ( ! (Helper::isDevEnv() || Helper::isTestEnv())) {
            /** @var HyperfResponse $response */
            $response = ApplicationContext::getContainer()->get(HyperfResponse::class);

            return $response->json(CommonResult::make(ErrorCode::ErrBonusDisabled, '功能未启用')->toArray())->withStatus(403);
        }

        return $handler->handle($request);
    }

}
