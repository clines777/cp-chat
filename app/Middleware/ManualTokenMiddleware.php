<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Lib\CommonResult;
use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Repository\Impl\ConfigRepository;
use Hyperf\Context\Context;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ManualTokenMiddleware implements MiddlewareInterface
{

    public function __construct(protected ContainerInterface $container) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = trim($request->getHeaderLine('auth'));
        if (empty($token)) {
            Log::error('请求未带Bearer Token', 'bearer_token_not_found');

            return $this->unauthorized(ErrorCode::ErrInvalidApiToken, 'auth failed');
        }

        /** @var ConfigRepository $configRepo */
        $configRepo = Container::get(ConfigRepository::class);

        $checkToken = $configRepo->getManualToken();
        if ($checkToken !== $token) {
            return $this->unauthorized(ErrorCode::ErrInvalidApiToken, 'auth failed 2');
        }

        return $handler->handle($request);
    }

    /**
     * 验证未通过.
     *
     * @param  int     $code
     * @param  string  $msg
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function unauthorized(int $code, string $msg): ResponseInterface
    {
        $response = Context::get(ResponseInterface::class);
        $payload  = CommonResult::make($code, $msg)->jsonSerialize();

        return $response
            ->withStatus(401)->withHeader('Content-Type', 'application/json')->withBody(new SwooleStream($payload));
    }

}
