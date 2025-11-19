<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Lib\CommonResult;
use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Repository\Impl\ConfigRepository;
use Hyperf\Context\ApplicationContext;
use Hyperf\HttpServer\Contract\ResponseInterface as HyperfResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 检查是否启用红包活动
 */
class CheckBonusMiddleware implements MiddlewareInterface
{

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var ConfigRepository $configRepo */
        $configRepo  = Container::get(ConfigRepository::class);
        $isBonusOpen = $configRepo->isBonusOpen();
        if ( ! $isBonusOpen) {
            return $this->responseErr();
        }

        return $handler->handle($request);
    }

    protected function responseErr(): PsrResponseInterface
    {
        /** @var HyperfResponse $response */
        $response = ApplicationContext::getContainer()->get(HyperfResponse::class);

        return $response->json(CommonResult::make(ErrorCode::ErrBonusDisabled, '红包功能未启用')->toArray())->withStatus(403);
    }

}
