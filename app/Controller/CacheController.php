<?php

declare(strict_types=1);

namespace App\Controller;

use App\Lib\CommonResult;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Helper;
use App\Middleware\ManualTokenMiddleware;
use App\Service\Impl\CacheService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Controller(prefix: "cache")]
#[Middleware(ManualTokenMiddleware::class)]
class CacheController extends AbstractController
{

    /**
     * 清除缓存.
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping("clear")]
    public function cleanConfigCache(ServerRequestInterface $request): ResponseInterface
    {
        $param = (array)$request->getParsedBody();

        /** @var CacheService $service */
        $service = Container::get(CacheService::class);
        try {
            $result = $service->clear($param);
        } catch (\Throwable $e) {
            $msg = '清缓存异常! 讯息: '.Helper::getExpDetails($e);
            Log::error($msg, 'clean_cache_err');
            $result = CommonResult::error($msg);
        }

        return $this->response($result->toArray());
    }

}
