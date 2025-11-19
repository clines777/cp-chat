<?php

namespace App\Controller;

use App\Lib\CommonResult;
use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Facade\Redis;
use App\Lib\Helper;
use App\Middleware\ExtDecryptMiddleware;
use App\Service\Impl\AdminCmdService;
use App\Service\Impl\AdminGroupService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 外部(彩票boss呼叫API Controller, 群相关)
 */
#[Controller(prefix: "/admin/group")]
#[Middleware(ExtDecryptMiddleware::class)]
class ExtAdminGroupController extends AbstractController
{

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 查询群号是否存在.
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return ResponseInterface
     */
    #[PostMapping(path: "code-exists")]
    public function getByCode(ServerRequestInterface $request): ResponseInterface
    {
        $param = (array)$request->getParsedBody();

        /** @var \App\Service\Impl\AdminGroupService $service */
        $service = Container::get(AdminGroupService::class);
        try {
            $resp = $service->findGroupByCode($param);
        } catch (\Throwable $e) {
            Log::error(sprintf('查询群号异常! 讯息: %s', Helper::getExpDetails($e)));
            $resp = CommonResult::error(Helper::getExpDetails($e));
        }

        return $this->response($resp->toArray());
    }

    /**
     * 查询群号是否存在.
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return ResponseInterface
     */
    #[PostMapping(path: "get-by-id")]
    public function getById(ServerRequestInterface $request): ResponseInterface
    {
        $param = (array)$request->getParsedBody();
        /** @var \App\Service\Impl\AdminGroupService $service */
        $service = Container::get(AdminGroupService::class);
        try {
            $resp = $service->findGroupById($param);
        } catch (\Throwable $e) {
            Log::error(sprintf('查询群号异常! 讯息: %s', Helper::getExpDetails($e)));
            $resp = CommonResult::error(Helper::getExpDetails($e));
        }

        return $this->response($resp->toArray());
    }

    /**
     * 彩票后台群组列表
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping(path: "admin-index")]
    public function getAdminGroupIndex(ServerRequestInterface $request): ResponseInterface
    {
        $param = (array)$request->getParsedBody();

        /** @var \App\Service\Impl\AdminGroupService $service */
        $service = Container::get(AdminGroupService::class);
        try {
            $result = $service->adminGroupIndex($param);
        } catch (\Throwable $e) {
            Log::error(sprintf('彩票后台获取房间列表异常! 参数: %s, 讯息: %s', json_encode($param, 256), Helper::getExpDetails($e)), 'admin_get_group_index_err');
            $result = CommonResult::error("获取失败:".Helper::getExpDetails($e));
        }

        return $this->response($result->toArray());
    }

    /**
     * 建立群组.
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping(path: "create")]
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $param = (array)$request->getParsedBody();

        if (empty($param['site_bid']) || empty($param['code'])) {
            return $this->response(CommonResult::invalidParam('参数错误')->toArray());
        }

        $lockKey = sprintf('create_group_%s_%s', $param['site_bid'], $param['code']);
        if ( ! Redis::lock($lockKey)) {
            return $this->response(CommonResult::make(ErrorCode::ErrBeingLocked, '处理中, 请稍后')->toArray());
        }

        /** @var AdminGroupService $adminGroupService */
        $adminGroupService = Container::get(AdminGroupService::class);

        try {
            $resp = $adminGroupService->adminCreateGroup($param);
        } catch (\Throwable $e) {
            Log::error(sprintf('创建聊天群异常! 讯息: %s', Helper::getExpDetails($e)), 'group_create_err');
            $resp = CommonResult::error(Helper::getExpDetails($e));
        }
        finally {
            Redis::unlock($lockKey);
        }

        return $this->response($resp->toArray());
    }

    /**
     * 更新群组.
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping(path: "update")]
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $param = (array)$request->getParsedBody();
        if (empty($param['site_bid']) || empty($param['code'])) {
            return $this->response(CommonResult::invalidParam('参数错误')->toArray());
        }

        $lockKey = sprintf('update_group_%s_%s', $param['site_bid'], $param['code']);
        if ( ! Redis::lock($lockKey)) {
            return $this->response(CommonResult::make(ErrorCode::ErrBeingLocked, '处理中, 请稍候')->toArray());
        }

        /** @var AdminGroupService $adminGroupService */
        $adminGroupService = Container::get(AdminGroupService::class);

        try {
            $result = $adminGroupService->adminUpdateGroup($param);
        } catch (\Throwable $e) {
            Log::error(sprintf('更新群组失败, 讯息: %s', Helper::getExpDetails($e)), 'group_update_err');
            $result = CommonResult::error('操作失败');
        }
        finally {
            Redis::unlock($lockKey);
        }

        return $this->response($result->toArray());
    }

    /**
     * 更新群组.
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping(path: "update-quota")]
    public function updateQuota(ServerRequestInterface $request): ResponseInterface
    {
        $param = (array)$request->getParsedBody();
        if (empty($param['group_id'])) {
            return $this->response(CommonResult::invalidParam('参数错误'));
        }

        $lockKey = sprintf('update_group_%s_%s', $param['site_bid'], $param['group_id']);
        if ( ! Redis::lock($lockKey)) {
            return $this->response(CommonResult::make(ErrorCode::ErrBeingLocked, '处理中, 请稍候')->toArray());
        }

        /** @var AdminGroupService $adminGroupService */
        $adminGroupService = Container::get(AdminGroupService::class);

        try {
            $result = $adminGroupService->adminUpdateGroupQuota($param);
        } catch (\Throwable $e) {
            Log::error(sprintf('更新群组失败, 讯息: %s', Helper::getExpDetails($e)), 'updateQuota_err');
            $result = CommonResult::error('操作失败');
        }
        finally {
            Redis::unlock($lockKey);
        }

        return $this->response($result->toArray());
    }

    /**
     * 解散群组.
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping(path: "dismiss")]
    public function dismissGroup(ServerRequestInterface $request): ResponseInterface
    {
        $param = (array)$request->getParsedBody();
        if (empty($param['admin_id']) || empty($param['group_id'])) {
            return $this->response(CommonResult::error('参数错误'));
        }

        $lockKey = sprintf('admin_g_dismiss_%s_%s_%s', $param['admin_id'], $param['group_id'], $param['site_bid']);
        if ( ! Redis::lock($lockKey)) {
            return $this->response(CommonResult::make(ErrorCode::ErrBeingLocked, '处理中, 请稍候'));
        }

        try {
            /** @var AdminCmdService $adminCmdService */
            $adminCmdService = Container::get(AdminCmdService::class);

            $result = $adminCmdService->dismissGroup($param);
            if ( ! $result->isSuccess()) {
                return $this->response($result->toArray());
            }

            $result = $adminCmdService->queueDismissGroup($param['group_id']);
        } catch (\Throwable $e) {
            Log::error('解散群组异常! 讯息: '.Helper::getExpDetails($e), 'dismissGroup_err');
            $result = CommonResult::error('操作失败');
        }
        finally {
            Redis::unlock($lockKey);
        }

        return $this->response($result->toArray());
    }

    /**
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping(path: "admin-quota-log-index")]
    public function getGroupQuotaLogs(ServerRequestInterface $request): ResponseInterface
    {
        $param = (array)$request->getParsedBody();

        /** @var AdminGroupService $adminGroupService */
        $adminGroupService = Container::get(AdminGroupService::class);

        try {
            $result = $adminGroupService->getGroupQuotaLog($param);
        } catch (\Throwable $e) {
            Log::error('后台获取群额度纪录异常! 讯息: '.Helper::getExpDetails($e), 'getGroupQuotaLogs_err');
            $result = CommonResult::error('获取失败');
        }

        return $this->response($result->toArray());
    }

}