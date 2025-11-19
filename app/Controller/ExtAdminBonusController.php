<?php

namespace App\Controller;

use App\Lib\CommonResult;
use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Facade\Redis;
use App\Lib\Helper;
use App\Middleware\CheckBonusMiddleware;
use App\Middleware\ExtDecryptMiddleware;
use App\Service\Impl\AdminBonusService;
use App\Service\Impl\LuckyMoneyService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Controller(prefix: "/admin/bonus")]
#[Middleware(ExtDecryptMiddleware::class)]
#[Middleware(CheckBonusMiddleware::class)]
class ExtAdminBonusController extends AbstractController
{

    /**
     * 创建系统红包(游戏币).
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping(path: "create-sys-lm")]
    public function createSysLm(ServerRequestInterface $request): ResponseInterface
    {
        $param = (array)$request->getParsedBody();

        /** @var LuckyMoneyService $luckyMoneyService */
        $luckyMoneyService = Container::get(LuckyMoneyService::class);

        $lockKey = 'lock_create_sys_lm'.$param['site_bid'];

        if ( ! Redis::lock($lockKey)) {
            return $this->response(CommonResult::make(ErrorCode::ErrBeingLocked));
        }

        try {
            $result = $luckyMoneyService->createSysLuckyMoney($param);
        } catch (\Throwable $e) {
            Log::error(sprintf('创建系统红包异常! 数据: %s, 讯息: %s', json_encode($param, 256), Helper::getExpDetails($e)), 'create_sys_lm_err');
            $result = CommonResult::error('创建失败');
        }
        finally {
            Redis::unlock($lockKey);
        }

        return $this->response($result->toArray());
    }

    /**
     * 关闭系统红包
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping(path: "close-sys-lm")]
    public function closeSysLm(ServerRequestInterface $request): ResponseInterface
    {
        $param = (array)$request->getParsedBody();
        /** @var LuckyMoneyService $luckyMoneyService */
        $luckyMoneyService = Container::get(LuckyMoneyService::class);

        $lockKey = 'lock_close_sys_lm'.$param['site_bid'];
        if ( ! Redis::lock($lockKey)) {
            return $this->response(CommonResult::make(ErrorCode::ErrBeingLocked));
        }

        try {
            $result = $luckyMoneyService->closeSysLuckyMoney($param);
        } catch (\Throwable $e) {
            Log::error(sprintf('关闭系统红包异常! 参数: %s, 讯息: %s', json_encode($param, 256), Helper::getExpDetails($e)));
            $result = CommonResult::make(ErrorCode::ErrBeingLocked);
        }
        finally {
            Redis::unlock($lockKey);
        }

        return $this->response($result->toArray());
    }

    /**
     * 后台取红包列表
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping(path: "lm-index")]
    public function getAdminLmList(ServerRequestInterface $request): ResponseInterface
    {
        $param = (array)$request->getParsedBody();
        /** @var AdminBonusService $adminBonusService */
        $adminBonusService = Container::get(AdminBonusService::class);

        try {
            $result = $adminBonusService->getAdminLmIndex($param);
        } catch (\Throwable $e) {
            Log::error(sprintf('后台获取红包列表异常! 参数: %s, 讯息: %s', json_encode($param, 256), Helper::getExpDetails($e)), 'get_admin_lm_list_err');
            $result = CommonResult::error('获取失败');
        }

        return $this->response($result->toArray());
    }

    /**
     * 查询红包状态
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping(path: "lm-state")]
    public function getAdminLmState(ServerRequestInterface $request): ResponseInterface
    {
        $param = (array)$request->getParsedBody();
        /** @var AdminBonusService $adminBonusService */
        $adminBonusService = Container::get(AdminBonusService::class);

        try {
            $result = $adminBonusService->getLmState($param);
        } catch (\Throwable $e) {
            Log::error(sprintf('获取后台红包状态异常! 参数: %s, 讯息: %s', json_encode($param, 256), Helper::getExpDetails($e)), 'admin_lm_state_err');
            $result = CommonResult::error('获取失败');
        }

        return $this->response($result->toArray());
    }

    /**
     * 后台取红包列表
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping(path: "lm-record-index")]
    public function getAdminLmRecordIndex(ServerRequestInterface $request): ResponseInterface
    {
        $param = (array)$request->getParsedBody();
        /** @var AdminBonusService $adminBonusService */
        $adminBonusService = Container::get(AdminBonusService::class);

        try {
            $result = $adminBonusService->getAdminLmRecordIndex($param);
        } catch (\Throwable $e) {
            Log::error(sprintf('后台获取红包派奖纪录列表异常! 参数: %s, 讯息: %s', json_encode($param, 256), Helper::getExpDetails($e)), 'get_admin_lm_record_index_err');
            $result = CommonResult::error('获取失败');
        }

        return $this->response($result->toArray());
    }

    /**
     * 取系統紅包指定用戶
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping(path: "admin-sys-lm-user")]
    public function getSysLmUsers(ServerRequestInterface $request): ResponseInterface
    {
        $param = (array)$request->getParsedBody();

        /** @var AdminBonusService $adminBonusService */
        $adminBonusService = Container::get(AdminBonusService::class);

        try {
            $result = $adminBonusService->getAdminSysLmUsers($param);
        } catch (\Throwable $e) {
            Log::error(sprintf('獲取系統紅包用戶列表異常! 訊息: %s', Helper::getExpDetails($e)), 'get_sys_lm_users_err');
            $result = CommonResult::error('獲取失敗');
        }

        return $this->response($result->toArray());
    }

}