<?php

namespace App\Controller;

use App\Lib\CommonResult;
use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Facade\Redis;
use App\Lib\Helper;
use App\Middleware\AuthTokenMiddleware;
use App\Middleware\BlockGroupLmMiddleware;
use App\Middleware\CheckBonusMiddleware;
use App\Service\Impl\LuckyMoneyService;
use Hyperf\Context\Context;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Controller(prefix: "/app/bonus")]
#[Middleware(AuthTokenMiddleware::class)]
#[Middleware(CheckBonusMiddleware::class)]
class AppBonusController extends AbstractController
{

    /**
     * 获取创建红包资讯.
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[GetMapping('create-lucky-money-info')]
    #[Middleware(BlockGroupLmMiddleware::class)]
    public function getCreateInfo(ServerRequestInterface $request): ResponseInterface
    {
        $user = Context::get('user');

        /** @var LuckyMoneyService $luckyMoneyService */
        $luckyMoneyService = Container::get(LuckyMoneyService::class);
        try {
            $result = $luckyMoneyService->getCreateLuckyMoneyInfo($user);
        } catch (\Throwable $e) {
            Log::error(sprintf('获取发红包页面数据异常! 用户: %s, 讯息: %s', json_encode($user, 256), Helper::getExpDetails($e)), 'getCreateInfo');
            $result = CommonResult::error('获取失败');
        }

        return $this->response($result->toArray());
    }

    /**
     * 执行创建红包
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping('create-lucky-money')]
    #[Middleware(BlockGroupLmMiddleware::class)]
    public function createLuckyMoney(ServerRequestInterface $request): ResponseInterface
    {
        $user    = Context::get('user');
        $param   = (array)$request->getParsedBody();
        $lockKey = sprintf('create_lucky_money_%s', $user['id']);
        if ( ! Redis::lock($lockKey)) {
            return $this->response(CommonResult::make(ErrorCode::ErrBeingLocked, '处理中, 请稍候')->toArray());
        }

        try {
            /** @var LuckyMoneyService $luckyMoneyService */
            $luckyMoneyService = Container::get(LuckyMoneyService::class);
            $result            = $luckyMoneyService->createLuckyMoney($user, $param);
        } catch (\Throwable $e) {
            Log::error(sprintf('创建红包异常! 用户: %s, 讯息: %s', $user['id'], Helper::getExpDetails($e)), 'createLuckyMoney_err');
            $result = CommonResult::make(ErrorCode::ErrCreateLuckyMoneyFailed);
        }
        finally {
            Redis::unlock($lockKey);
        }

        return $this->response($result->toArray());
    }

    /**
     * 取抢紅包頁資訊.
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[GetMapping("lucky-money-info")]
    public function getLuckyMoneyInfo(ServerRequestInterface $request): ResponseInterface
    {
        $user  = Context::get('user');
        $param = $request->getQueryParams();

        try {
            /** @var LuckyMoneyService $luckyMoneyService */
            $luckyMoneyService = Container::get(LuckyMoneyService::class);
            $result            = $luckyMoneyService->getGrabPageInfo($user, $param);
        } catch (\Throwable $e) {
            Log::error(
                sprintf(
                    '獲取紅包資訊異常! 用戶: %s, 參數: %s, 訊息: %s',
                    json_encode($user, 256),
                    json_encode($param, 256),
                    Helper::getExpDetails($e),
                ),
                'getLuckyMoneyInfo_err',
            );
            $result = CommonResult::error('獲取失敗');
        }

        return $this->response($result->toArray());
    }

    /**
     * 搶紅包
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping('grab-lucky-money')]
    public function grabLuckyMoney(ServerRequestInterface $request): ResponseInterface
    {
        $user    = Context::get('user');
        $lockKey = sprintf('grab_lucky_money_%s', $user['id']);
        if ( ! Redis::lock($lockKey)) {
            return $this->response(CommonResult::error('您手速太快了, 請稍候'));
        }

        try {
            /** @var LuckyMoneyService $luckyMoneyService */
            $luckyMoneyService = Container::get(LuckyMoneyService::class);
            $result            = $luckyMoneyService->grabLuckyMoney($user, (array)$request->getParsedBody());
        } catch (\Throwable $e) {
            Log::error(sprintf('紅包領取異常! 用戶: %s, 訊息: %s', $user['id'], Helper::getExpDetails($e)));
            $result = CommonResult::error('領取失敗');
        }
        finally {
            Redis::unlock($lockKey);
        }

        return $this->response($result->toArray());
    }

    /**
     * 取红包派奖纪录
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[GetMapping('lucky-money-record')]
    public function getLuckyMoneyRecord(ServerRequestInterface $request): ResponseInterface
    {
        $user   = Context::get('user');
        $params = (array)$request->getQueryParams();

        try {
            /** @var LuckyMoneyService $luckyMoneyService */
            $luckyMoneyService = Container::get(LuckyMoneyService::class);
            $respData          = $luckyMoneyService->getRecordInfo($user, $params);
        } catch (\Throwable $e) {
            Log::error(sprintf('获取红包派奖纪录异常! 讯息: %s', Helper::getExpDetails($e)), 'getLuckyMoneyRecord_err');
            $respData = CommonResult::error('获取失败')->toArray();
        }

        return $this->response($respData);
    }

    /**
     * 關閉紅包
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping('close-lucky-money')]
    #[Middleware(BlockGroupLmMiddleware::class)]
    public function terminateLuckyMoney(ServerRequestInterface $request): ResponseInterface
    {
        $user   = Context::get('user');
        $params = (array)$request->getParsedBody();

        if (empty($params['lm_id'])) {
            return $this->response(CommonResult::invalidParam('參數錯誤'));
        }

        $lockKey = sprintf('close_lucky_money_%s', $user['id']);

        if ( ! Redis::lock($lockKey)) {
            return $this->response(CommonResult::make(ErrorCode::ErrBeingLocked, '处理中, 请稍候')->toArray());
        }

        try {
            /** @var LuckyMoneyService $luckyMoneyService */
            $luckyMoneyService = Container::get(LuckyMoneyService::class);
            $result            = $luckyMoneyService->closeLuckyMoney($user, $params);
        } catch (\Throwable $e) {
            Log::error(sprintf('收回红包处理异常! 参数: %s 讯息: %s', json_encode($params, 256), Helper::getExpDetails($e)), 'user_close_lucky_money_err');
            $result = CommonResult::error('收回失败');
        }
        finally {
            Redis::unlock($lockKey);
        }

        return $this->response($result->toArray());
    }

}