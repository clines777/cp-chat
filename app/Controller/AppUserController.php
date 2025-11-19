<?php

namespace App\Controller;

use App\Lib\CommonResult;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Facade\Redis;
use App\Lib\Helper;
use App\Middleware\AuthTokenMiddleware;
use App\Service\Impl\LuckyMoneyService;
use App\Service\Impl\UserService;
use Hyperf\Context\Context;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Controller(prefix: "/app/user")]
#[Middleware(AuthTokenMiddleware::class)]
class AppUserController extends AbstractController
{

    protected UserService $userService;

    public function __construct(UserService $userService)
    {
        parent::__construct();
        $this->userService = $userService;
    }

    /**
     * 取默认头像清单.
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return ResponseInterface
     */
    #[GetMapping('avatar')]
    public function avatars(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $resp = $this->userService->getAvatars();
        } catch (\Throwable $e) {
            Log::error("取得用户头像列表异常! 讯息:".Helper::getExpDetails($e), 'get_avatars_err');
            $resp = CommonResult::error('获取头像清单失败');
        }

        return $this->response($resp->toArray());
    }

    /**
     * 设置用户头像.
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping('avatar')]
    public function avatar(ServerRequestInterface $request): ResponseInterface
    {
        $user = Context::get('user');
        $body = (array)$request->getParsedBody();

        $lockKey = sprintf('set_avatar_%s', $user['id']);
        if ( ! Redis::lock($lockKey)) {
            return $this->response(CommonResult::error('处理中, 请稍候'));
        }

        try {
            $resp = $this->userService->setAvatar($user, $body);
        } catch (\Throwable $e) {
            Log::error("设置用户头像异常! 讯息:".Helper::getExpDetails($e), 'user_set_avatar_err');
            $resp = CommonResult::error('操作失败');
        }
        finally {
            Redis::unlock($lockKey);
        }

        return $this->response($resp->toArray());
    }

    /**
     * 取用户红包纪录
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[GetMapping('lucky-money-record')]
    public function getUserLuckyMoneyRecord(ServerRequestInterface $request): ResponseInterface
    {
        $user  = Context::get('user');
        $param = $request->getQueryParams();
        try {
            /** @var LuckyMoneyService $luckyMoneyService */
            $luckyMoneyService = Container::get(LuckyMoneyService::class);
            $respData          = $luckyMoneyService->getUserLmRecord($user, $param);
        } catch (\Throwable $e) {
            Log::error(sprintf('取用户红包纪录异常! 用户: %s, 讯息: %s', json_encode($user, JSON_UNESCAPED_UNICODE), Helper::getExpDetails($e)), 'getUserLuckyMoneyRecord_err');
            $respData = CommonResult::error('获取失败')->toArray();
        }

        return $this->response($respData);
    }

}