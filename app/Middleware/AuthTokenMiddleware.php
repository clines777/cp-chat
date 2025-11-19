<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Lib\CommonResult;
use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Repository\Impl\SessionRepository;
use Hyperf\Context\Context;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * API Token验证
 */
class AuthTokenMiddleware implements MiddlewareInterface
{

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        Context::set(Log::LogChanName, Log::LogChanApi);
        $auth = trim($request->getHeaderLine('Authorization'));
        if (empty($auth) || ! str_starts_with($auth, 'Bearer ')) {
            Log::error('请求未带Bearer Token', 'bearer_token_not_found');

            return $this->unauthorized(ErrorCode::ErrInvalidApiToken, '身份验证失败A0');
        }

        $token = substr($auth, 7);
        /** @var SessionRepository $sessionRepo */
        $sessionRepo = Container::get(SessionRepository::class);
        $user        = $sessionRepo->rGetApiTokenInfo($token);

        if (empty($user)) {
            Log::error("中介层API Token验证失败! token超时或不合法:".$token, 'api_token_not_found');

            return $this->unauthorized(ErrorCode::ErrInvalidApiToken, '身份验证失败A1');
        }

        Context::set('user', $user);

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