<?php

namespace App\Controller;

use App\Lib\CommonResult;
use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Facade\Redis;
use App\Lib\Facade\Validator;
use App\Lib\Helper;
use App\Middleware\ExtDecryptMiddleware;
use App\Service\Impl\AdminCmdService;
use App\Service\Impl\AdminUserService;
use App\Service\Impl\MarqueeService;
use App\Service\Impl\StateService;
use App\Service\Impl\SysChatService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 外部(彩票boss呼叫API Controller, 用户相关操作)
 */
#[Controller(prefix: "/admin/user")]
#[Middleware(ExtDecryptMiddleware::class)]
class ExtAdminUserController extends AbstractController
{

    protected AdminCmdService $adminCmdService;

    public function __construct(AdminCmdService $adminCmdService)
    {
        parent::__construct();
        $this->adminCmdService = $adminCmdService;
    }

    /**
     * 后台取用户列表
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping('admin-index')]
    public function getAdminIndex(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array)$request->getParsedBody();

        /** @var AdminUserService $adminUserService */
        $adminUserService = Container::get(AdminUserService::class);
        try {
            $result = $adminUserService->getUserAdminIndex($params);
        } catch (\Throwable $e) {
            $msg = sprintf('获取用户列表失败! 参数: %s, 讯息: %s', json_encode($params, 256), Helper::getExpDetails($e));
            Log::error($msg, 'user_getAdminIndex_err');
            $result = CommonResult::error($msg);
        }

        return $this->response($result->toArray());
    }

    /**
     *  后台取单用户所属群组.
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping('group-index')]
    public function getAdminGroupOfUser(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array)$request->getParsedBody();

        /** @var AdminUserService $adminUserService */
        $adminUserService = Container::get(AdminUserService::class);
        try {
            $result = $adminUserService->getAdminGroupUserIndex($params);
        } catch (\Throwable $e) {
            Log::error(sprintf('获取用户群组异常! 讯息: %s', Helper::getExpDetails($e)), 'getAdminGroupOfUser');
            $result = CommonResult::error("获取失败");
        }

        return $this->response($result->toArray());
    }

    /**
     * 用户禁言.
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return ResponseInterface
     */
    #[PostMapping('ban')]
    public function banUser(ServerRequestInterface $request): ResponseInterface
    {
        $vResult = Validator::validate((array)$request->getParsedBody(), [
            'user_id'  => 'required|integer',
            'group_id' => 'required|integer',
            'admin_id' => 'required|integer',
        ]);
        if ( ! $vResult->success) {
            return $this->response(CommonResult::invalidParam("参数验证错误!".$vResult->msg));
        }

        $lockKey = sprintf(
            'ban_user_lock_%s_%s',
            $vResult->validated['user_id'],
            $vResult->validated['group_id'],
        );
        if ( ! Redis::lock($lockKey)) {
            return $this->response(CommonResult::make(ErrorCode::ErrBeingLocked, '处理中, 请稍后'));
        }

        try {
            $result = $this->adminCmdService->banUser($vResult->validated['user_id'], $vResult->validated['group_id'], $vResult->validated['admin_id']);
            if ( ! $result->isSuccess()) {
                return $this->response($result->toArray());
            }

            $result = $this->adminCmdService->queueBanUser($result->data);
        } catch (\Throwable $e) {
            Log::error("处理后台管理者操作用户禁言异常! 讯息: ".Helper::getExpDetails($e), 'admin_ban_user_err');
            $result = CommonResult::error("用户禁言操作失败!");
        }
        finally {
            Redis::unlock($lockKey);
        }

        return $this->response($result->toArray());
    }

    /**
     * 用户解禁
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping('unban')]
    public function unbanUser(ServerRequestInterface $request): ResponseInterface
    {
        $vResult = Validator::validate((array)$request->getParsedBody(), [
            'user_id'  => 'required|integer',
            'group_id' => 'required|integer',
            'admin_id' => 'required|integer',
        ]);
        if ( ! $vResult->success) {
            return $this->response(CommonResult::invalidParam("用户解禁, 参数验证错误!".$vResult->msg));
        }

        $lockKey = sprintf(
            'unban_user_lock_%s_%s',
            $vResult->validated['user_id'],
            $vResult->validated['group_id'],
        );
        if ( ! Redis::lock($lockKey, 60)) {
            return $this->response(CommonResult::make(ErrorCode::ErrBeingLocked, '用户解禁处理中, 请稍候'));
        }

        try {
            $result = $this->adminCmdService->unbanUser($vResult->validated['user_id'], $vResult->validated['group_id'], $vResult->validated['admin_id']);

            $this->adminCmdService->queueUnbanUser($result->data);
        } catch (\Throwable $e) {
            Log::error("处理后台管理者操作用户解禁异常! 讯息: ".Helper::getExpDetails($e), 'admin_unban_user_err');
            $result = CommonResult::error("用户解禁操作失败!");
        }
        finally {
            Redis::unlock($lockKey);
        }

        return $this->response($result->toArray());
    }

    /**
     * 用户踢群
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping('kick')]
    public function kickUser(ServerRequestInterface $request): ResponseInterface
    {
        $vResult = Validator::validate((array)$request->getParsedBody(), [
            'user_id'  => 'required|integer',
            'group_id' => 'required|integer',
            'admin_id' => 'required|integer',
        ]);
        if ( ! $vResult->success) {
            return $this->response(CommonResult::invalidParam("用户踢群, 参数验证错误!".$vResult->msg));
        }

        $lockKey = sprintf(
            'kick_user_lock_%s',
            $vResult->validated['user_id'],
        );
        if ( ! Redis::lock($lockKey, 60)) {
            return $this->response(CommonResult::make(ErrorCode::ErrBeingLocked, '用户踢群处理中, 请稍候'));
        }

        try {
            $result = $this->adminCmdService->kickUser($vResult->validated['user_id'], $vResult->validated['group_id'], $vResult->validated['admin_id']);
            if ( ! $result->isSuccess()) {
                return $this->response($result->toArray());
            }

            $groupUser = $result->data ?? [];
            $queueData = ! empty($groupUser) ? [(object)$groupUser] : [];
            $result    = $this->adminCmdService->queueKickUser($queueData);
        } catch (\Throwable $e) {
            Log::error("用户踢群, 送消息进管道异常! 讯息:".Helper::getExpDetails($e));
            $result = CommonResult::error("操作失败");
        }
        finally {
            Redis::unlock($lockKey);
        }

        return $this->response($result->toArray());
    }

    /**
     * 将用户踢出全部群
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping('kick-all')]
    public function kickUserAll(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array)$request->getParsedBody();
        if (empty($params['user_id']) || empty($params['admin_id'])) {
            return $this->response(CommonResult::invalidParam('参数错误'));
        }

        $lockKey = sprintf('kick_user_all_lock_%s', $params['user_id']);
        if ( ! Redis::lock($lockKey, 60)) {
            return $this->response(CommonResult::make(ErrorCode::ErrBeingLocked, '处理中, 请稍候'));
        }

        /** @var \App\Service\Impl\AdminCmdService $service */
        $service = Container::get(AdminCmdService::class);
        try {
            $result = $service->kickUserAll($params['user_id'], $params['admin_id']);
        } catch (\Throwable $e) {
            $msg = sprintf("用户全部踢群异常! 用户ID: %s 讯息: %s", $params['user_id'], Helper::getExpDetails($e));
            Log::error($msg, 'user_kick_all_err');
            $result = CommonResult::error($msg);
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
    #[PostMapping('global-ban')]
    public function globalBan(ServerRequestInterface $request): ResponseInterface
    {
        $param   = (array)$request->getParsedBody();
        $vResult = Validator::validate(
            $param,
            ['site_bid' => 'required|string', 'user_id' => 'required|integer'],
            ['site_bid.required' => '缺少site_bid参数', 'user_id.required' => '缺少user_id参数'],
        );

        if ( ! $vResult->success) {
            return $this->response(CommonResult::invalidParam("参数验证错误!".$vResult->msg)->toArray());
        }

        $lockKey = sprintf('global_ban_%s_%s', $param['site_bid'], $param['user_id']);
        if ( ! Redis::lock($lockKey, 10)) {
            return $this->response(CommonResult::make(ErrorCode::ErrBeingLocked, '全域禁言处理中, 请稍候')->toArray());
        }

        try {
            $result = $this->adminCmdService->globalBanUser($param['user_id'], $param['admin_id']);
            if ( ! $result->isSuccess()) {
                return $this->response(CommonResult::error('操作失败')->toArray());
            }

            $result = $this->adminCmdService->queueGlobalBan($result->data);
        } catch (\Throwable $e) {
            Log::error("彩票后台操作用户全域禁言异常! 讯息:".Helper::getExpDetails($e), 'globalBan_err');
            $result = CommonResult::error("操作失败");
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
    #[PostMapping('global-unban')]
    public function globalUnban(ServerRequestInterface $request): ResponseInterface
    {
        $param   = (array)$request->getParsedBody();
        $vResult = Validator::validate(
            $param,
            ['site_bid' => 'required|string', 'user_id' => 'required|integer'],
            ['site_bid.required' => '缺少site_bid参数', 'user_id.required' => '缺少user_id参数'],
        );

        if ( ! $vResult->success) {
            return $this->response(CommonResult::invalidParam("参数验证错误!".$vResult->msg));
        }

        $lockKey = sprintf('global_unban_%s_%s', $param['site_bid'], $param['user_id']);
        if ( ! Redis::lock($lockKey, 10)) {
            return $this->response(CommonResult::make(ErrorCode::ErrBeingLocked, '全域禁言处理中, 请稍候')->toArray());
        }

        try {
            $result = $this->adminCmdService->globalUnbanUser($param['user_id'], $param['admin_id']);
            if ( ! $result->isSuccess()) {
                return $this->response(CommonResult::error('操作失败'));
            }
            $result = $this->adminCmdService->queueGlobalUnban($result->data);
        } catch (\Throwable $e) {
            Log::error("彩票后台操作解除用户全域禁言异常! 讯息:".Helper::getExpDetails($e), 'globalUnban_err');
            $result = CommonResult::error("操作失败");
        }
        finally {
            Redis::unlock($lockKey);
        }

        return $this->response($result->toArray());
    }

    /**
     * 彩票后台发送系统消息给用户.
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping('send-sys-chat')]
    public function sysChat(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array)$request->getParsedBody();

        if (empty($params['site_bid']) || empty($params['admin_id'])) {
            return $this->response(CommonResult::invalidParam('參數錯誤')->toArray());
        }

        $lockKey = sprintf('send_sys_chat_%s_%s', $params['site_bid'], $params['admin_id']);
        if ( ! Redis::lock($lockKey)) {
            return $this->response(CommonResult::make(ErrorCode::ErrBeingLocked, '处理中, 请稍后')->toArray());
        }

        try {
            /** @var SysChatService $sysChatService */
            $sysChatService = Container::get(SysChatService::class);
            $result         = $sysChatService->addNormalSysChat($params);
        } catch (\Throwable $e) {
            Log::error(sprintf('彩票端发送系统消息异常! 原始数据: %s,  讯息: %s', json_encode($params, 256), Helper::getExpDetails($e)), 'send-sys-chat_err');
            $result = CommonResult::error("操作失败");
        }
        finally {
            Redis::unlock($lockKey);
        }

        return $this->response($result->toArray());
    }

    /**
     * //拉用户进群
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return ResponseInterface
     */
    #[PostMapping('add-group-user')]
    public function invite(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array)$request->getParsedBody();

        $lockKey = sprintf('invite_%s', $params['site_bid']);
        if ( ! Redis::lock($lockKey, 2)) {
            return $this->response(CommonResult::make(ErrorCode::ErrBeingLocked, '导入用户处理中, 请稍候')->toArray());
        }

        /** @var AdminUserService $service */
        $service = Container::get(AdminUserService::class);
        try {
            $result = $service->addUsersToGroups($params);
        } catch (\Throwable $e) {
            Log::error("彩票后台操作用户加群异常! 讯息:".Helper::getExpDetails($e), 'add-group-user_err');
            $result = CommonResult::error("操作失败");
        }
        finally {
            Redis::unlock($lockKey);
        }

        return $this->response($result->toArray());
    }

    /**
     * 取后台系统消息列表.
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping('sys-chat-admin-index')]
    public function getAdminSysChatIndex(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array)$request->getParsedBody();

        /** @var SysChatService $sysChatService */
        $sysChatService = Container::get(SysChatService::class);

        try {
            $result = $sysChatService->getAdminIndex($params);
        } catch (\Throwable $e) {
            Log::error(sprintf('获取后台系统消息列表异常! 参数: %s, 讯息: %s', json_encode($params, 256), Helper::getExpDetails($e)), 'getAdminSysChatIndex_err');
            $result = CommonResult::error("获取失败");
        }

        return $this->response($result->toArray());
    }

    /**
     * 取后台系统消息列表.
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping('sys-chat-admin-user')]
    public function getAdminSysChatUser(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array)$request->getParsedBody();

        /** @var SysChatService $sysChatService */
        $sysChatService = Container::get(SysChatService::class);

        try {
            $result = $sysChatService->getAdminSysChatUser($params);
        } catch (\Throwable $e) {
            Log::error(sprintf('获取后台系统消息接收用户异常! 参数: %s, 讯息: %s', json_encode($params, 256), Helper::getExpDetails($e)), 'getAdminSysChatUser_err');
            $result = CommonResult::error("获取失败");
        }

        return $this->response($result->toArray());
    }

    /**
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping('marquee')]
    public function addMarque(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array)$request->getParsedBody();

        try {
            /** @var MarqueeService $marqueeService */
            $marqueeService = Container::get(MarqueeService::class);
            $result         = $marqueeService->processMarquee($params);
        } catch (\Throwable $e) {
            Log::error(sprintf('跑马灯发送异常! 原始数据: %s, 讯息: %s', json_encode($params), Helper::getExpDetails($e)), 'send_marquee_err');
            $result = CommonResult::error("添加失败");
        }

        return $this->response($result->toArray());
    }

    /**
     * 取用户日志.
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping('admin-user-log-index')]
    public function getAdminUserLogs(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array)$request->getParsedBody();

        /** @var AdminUserService $service */
        $service = Container::get(AdminUserService::class);
        try {
            $result = $service->getAdminUserLogs($params);
        } catch (\Throwable $e) {
            Log::error('获取后用户日志异常! 讯息: '.Helper::getExpDetails($e), 'admin-user-log-index_err');
            $result = CommonResult::error("获取失败!");
        }

        return $this->response($result->toArray());
    }

    /**
     * 切换群用户身份
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping('toggle-role')]
    public function toggleGroupUserRole(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array)$request->getParsedBody();
        /** @var AdminUserService $service */
        $service = Container::get(AdminUserService::class);

        $lockKey = sprintf('toggle_role_%s_%s_%s', $params['site_bid'], $params['group_id'], $params['user_id']);
        if ( ! Redis::lock($lockKey)) {
            return $this->response(CommonResult::make(ErrorCode::ErrBeingLocked, '处理中，请稍候'));
        }

        try {
            $result = $service->toggleGroupUserRole($params);
        } catch (\Throwable $e) {
            Log::error(sprintf('切换群用户身份异常! 参数: %s, 讯息: %s', json_encode($params, 256), Helper::getExpDetails($e)), 'toggleGroupUserRole_err');
            $result = CommonResult::error("操作失败");
        }
        finally {
            Redis::unlock($lockKey);
        }

        return $this->response($result->toArray());
    }

    /**
     * 查询伺服器状态
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping('get-server-state')]
    public function getServerState(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array)$request->getParsedBody();

        /** @var \App\Service\Impl\StateService $service */
        $service = Container::get(StateService::class);

        try {
            $result = $service->getServerState($params);
        } catch (\Throwable $e) {
            Log::error(sprintf('获取server状态异常! 讯息: %s', Helper::getExpDetails($e)), 'get-server-state_err');
            $result = CommonResult::error("获取伺服器状态异常!");
        }

        return $this->response($result->toArray());
    }

}