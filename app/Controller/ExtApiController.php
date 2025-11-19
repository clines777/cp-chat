<?php

namespace App\Controller;

use App\Lib\CommonResult;
use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Helper;
use App\Middleware\ExtDecryptMiddleware;
use App\Repository\Impl\ConfigRepository;
use App\Service\Impl\GroupUserService;
use App\Service\Impl\UserService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 外部(彩票back呼叫API Controller)
 */
#[Controller(prefix: "api")]
#[Middleware(ExtDecryptMiddleware::class)]
class ExtApiController extends AbstractController
{

    #[PostMapping("login/token")]
    public function loginToken(ServerRequestInterface $request): ResponseInterface
    {
        try {
            /** @var ConfigRepository $configRepo */
            $configRepo   = Container::get(ConfigRepository::class);
            $isServerOpen = $configRepo->isServerOpen();
            if ( ! $isServerOpen) {
                return $this->response(CommonResult::make(ErrorCode::ErrServiceClosed));
            }
        } catch (\Throwable $e1) {
            Log::error(sprintf('取得server服务状态异常! 讯息: %s', Helper::getExpDetails($e1)), 'loginToken_err');

            return $this->response(CommonResult::error('登入失败'));
        }

        try {
            /** @var UserService $userService */
            $userService = Container::get(UserService::class);
            $data        = $request->getParsedBody();

            $result = $userService->getLoginToken($data);

            return $this->response($result->toArray());
        } catch (\Throwable $e) {
            Log::error("外部获取登入Token异常:".Helper::getExpDetails($e), 'loginToken_err');

            return $this->response(CommonResult::error('登入失败'));
        }
    }

    /**
     * 取用户未读数.
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping("ext-unread")]
    public function getUserUnreadCount(ServerRequestInterface $request): ResponseInterface
    {
        try {
            /** @var ConfigRepository $configRepo */
            $configRepo   = Container::get(ConfigRepository::class);
            $isServerOpen = $configRepo->isServerOpen();
            if ( ! $isServerOpen) {
                return $this->response(CommonResult::make(ErrorCode::ErrServiceClosed));
            }
        } catch (\Throwable $e1) {
            Log::error(sprintf('取得server服务状态异常! 讯息: %s', Helper::getExpDetails($e1)), 'getUserUnreadCount_err');

            return $this->response(CommonResult::error('获取失败!')->toArray());
        }

        $param = $request->getParsedBody();
        try {
            /** @var GroupUserService $groupUserService */
            $groupUserService = Container::get(GroupUserService::class);
            $result           = $groupUserService->getUnreadCountByExtInfo($param);
        } catch (\Throwable $e2) {
            Log::error('彩票端取得用户未读数异常! 讯息: '.Helper::getExpDetails($e2), 'getUserUnreadCount_err');
            $result = CommonResult::error('获取失败!');
        }

        return $this->response($result->toArray());
    }

}