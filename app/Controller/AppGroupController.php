<?php

namespace App\Controller;

use App\Lib\CommonResult;
use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Facade\Redis;
use App\Lib\Helper;
use App\Middleware\AuthTokenMiddleware;
use App\Service\Impl\ChatService;
use App\Service\Impl\GroupService;
use App\Service\Impl\GroupUserService;
use App\Service\Impl\SysChatService;
use Hyperf\Context\Context;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Controller(prefix: "/app/group")]
#[Middleware(AuthTokenMiddleware::class)]
class AppGroupController extends AbstractController
{

    /**
     * 申请加群(用户主动加群)
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping(path: "join")]
    public function joinGroup(ServerRequestInterface $request): ResponseInterface
    {
        $user    = Context::get('user');
        $lockKey = sprintf('l_join_group_%s', $user['id']);
        if ( ! Redis::lock($lockKey)) {
            return $this->response(CommonResult::error('处理中, 请稍候'));
        }

        $input = (array)$request->getParsedBody();
        try {
            /** @var GroupUserService $groupUserService */
            $groupUserService = Container::get(GroupUserService::class);
            $result           = $groupUserService->userJoinGroup($user, $input);
        } catch (\Throwable $e) {
            Log::error('用户加群异常! 讯息:'.Helper::getExpDetails($e), 'userJoinGroup_err');
            $result = CommonResult::error("加入失败!");
        }
        finally {
            Redis::unlock($lockKey);
        }

        return $this->response($result->toArray());
    }

    /**
     * 申请退群(从群组清单移除, 之后要进要再加一次)
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping(path: "quit")]
    public function quitGroup(ServerRequestInterface $request): ResponseInterface
    {
        $user    = Context::get('user');
        $lockKey = sprintf('l_quit_group_%s', $user['id']);
        if ( ! Redis::lock($lockKey)) {
            return $this->response(CommonResult::error('处理中, 请稍候'));
        }

        $input = (array)$request->getParsedBody();
        try {
            /** @var GroupUserService $groupUserService */
            $groupUserService = Container::get(GroupUserService::class);
            $result           = $groupUserService->userQuitGroup($user, $input);
            if ( ! $result->isSuccess()) {
                return $this->response($result->toArray());
            }

            $result = $groupUserService->queueUserQuitGroup($result->data);
        } catch (\Throwable $e) {
            Log::error('用户加群异常! 讯息:'.Helper::getExpDetails($e), 'userQuitGroup_err');
            $result = CommonResult::error("退出失败!");
        }
        finally {
            Redis::unlock($lockKey);
        }

        return $this->response($result->toArray());
    }

    /**
     * 取群基本讯息(群聊信息页)
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[GetMapping(path: "info")]
    public function getInfo(ServerRequestInterface $request): ResponseInterface
    {
        try {
            /** @var GroupService $groupService */
            $groupService = Container::get(GroupService::class);
            $result       = $groupService->getGroupBasicInfo((array)$request->getQueryParams());
        } catch (\Throwable $e) {
            Log::error("获取群聊信息异常! 讯息:".Helper::getExpDetails($e), 'get_group_info_err');
            $result = CommonResult::error("获取失败");
        }

        return $this->response($result->toArray());
    }

    /**
     * 清空用户所属群信息.(该群当下最新讯息ID往前用户不再显示)
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping(path: "clean")]
    public function cleanChat(ServerRequestInterface $request): ResponseInterface
    {
        $user    = Context::get('user');
        $lockKey = sprintf('l_clean_chat_%s', $user['id']);
        if ( ! Redis::lock($lockKey)) {
            return $this->response(CommonResult::error('处理中, 请稍候'));
        }

        try {
            /** @var ChatService $chatService */
            $chatService = Container::get(ChatService::class);
            $result      = $chatService->cleanChat($user, (array)$request->getParsedBody());
        } catch (\Throwable $e) {
            Log::error('清空对话异常! 讯息:'.Helper::getExpDetails($e), 'cleanChat_err');
            $result = CommonResult::error("操作失败");
        }
        finally {
            Redis::unlock($lockKey);
        }

        return $this->response($result->toArray());
    }

    /**
     * 群管理员以上身份更新群信息.
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping(path: "update")]
    public function updateGroup(ServerRequestInterface $request): ResponseInterface
    {
        $user    = Context::get('user');
        $lockKey = sprintf('l_update_group_%s', $user['id']);
        if ( ! Redis::lock($lockKey)) {
            return $this->response(CommonResult::error('处理中, 请稍候'));
        }

        try {
            /** @var GroupService $groupService */
            $groupService = Container::get(GroupService::class);
            $result       = $groupService->updateGroup($user, (array)$request->getParsedBody());
        } catch (\Throwable $e) {
            Log::error('修改群信息异常! 讯息:'.Helper::getExpDetails($e), 'in_app_update_group_err');
            $result = CommonResult::error("操作失败");
        }
        finally {
            Redis::unlock($lockKey);
        }

        return $this->response($result->toArray());
    }

    /**
     * 群置顶.
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping(path: "pin")]
    public function pinGroup(ServerRequestInterface $request): ResponseInterface
    {
        $user    = Context::get('user');
        $lockKey = sprintf('l_pin_group_%s', $user['id']);
        if ( ! Redis::lock($lockKey)) {
            return $this->response(CommonResult::make(ErrorCode::ErrBeingLocked, '处理中, 请稍候'));
        }

        $param = (array)$request->getParsedBody();

        /** @var GroupUserService $groupUserService */
        $groupUserService = Container::get(GroupUserService::class);
        try {
            $result = $groupUserService->pinGroup($user, $param);
        } catch (\Throwable $e) {
            Log::error(sprintf('群置顶失败! 参数: %s, 讯息: %s', json_encode($param, 256), Helper::getExpDetails($e)), 'pinGroup_err');
            $result = CommonResult::make(ErrorCode::ErrPingGroupFailed, '操作失败');
        }
        finally {
            Redis::unlock($lockKey);
        }

        return $this->response($result->toArray());
    }

    /**
     * 群取消置顶.
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[PostMapping(path: "unpin")]
    public function unpinGroup(ServerRequestInterface $request): ResponseInterface
    {
        $user    = Context::get('user');
        $lockKey = sprintf('l_unpin_group_%s', $user['id']);
        if ( ! Redis::lock($lockKey)) {
            return $this->response(CommonResult::make(ErrorCode::ErrBeingLocked, '处理中, 请稍候'));
        }

        $param = (array)$request->getParsedBody();

        /** @var GroupUserService $groupUserService */
        $groupUserService = Container::get(GroupUserService::class);
        try {
            $result = $groupUserService->unpinGroup($user, $param);
        } catch (\Throwable $e) {
            Log::error(sprintf('取消群置顶失败! 参数: %s, 讯息: %s', json_encode($param, 256), Helper::getExpDetails($e)), 'unpinGroup_err');
            $result = CommonResult::make(ErrorCode::ErrUnpinGroupFailed, '<UNK>');
        }
        finally {
            Redis::unlock($lockKey);
        }

        return $this->response($result->toArray());
    }

    /**
     * 取大厅群列表
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[GetMapping(path: "lobby")]
    public function getLobbyGroup(ServerRequestInterface $request): ResponseInterface
    {
        $param = $request->getQueryParams();
        $user  = Context::get('user');

        /** @var GroupService $groupService */
        $groupService = Container::get(GroupService::class);

        try {
            $lobbyInfo = $groupService->getLobbyInfo($user);
            $result    = CommonResult::success('ok', $lobbyInfo);
        } catch (\Throwable $e) {
            Log::error(sprintf('获取大厅群列表失败! 参数: %s, 讯息: %s', json_encode($param, 256), Helper::getExpDetails($e)), 'api_getLobbyGroup_err');
            $result = CommonResult::error("获取大厅群列表失败");
        }

        return $this->response($result->toArray());
    }

    /**
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[GetMapping(path: "my")]
    public function getMyGroup(ServerRequestInterface $request): ResponseInterface
    {
        $param = $request->getQueryParams();
        $user  = Context::get('user');

        /** @var GroupUserService $groupUserService */
        $groupUserService = Container::get(GroupUserService::class);
        /** @var SysChatService $sysChatService */
        $sysChatService = Container::get(SysChatService::class);

        try {
            $myGroup      = $groupUserService->getUserGroupList($user['id'], $param);
            $sysGroupInfo = $sysChatService->getSysChatPreview($user['id']);
            $result       = CommonResult::success('ok', ['my_group' => $myGroup, 'sys_group' => $sysGroupInfo]);
        } catch (\Throwable $e) {
            Log::error(sprintf('获取聊天群列表失败! 参数: %s, 讯息: %s', json_encode($param, 256), Helper::getExpDetails($e)), 'api_getMyGroup_err');
            $result = CommonResult::error("获取聊天群列表失败");
        }

        return $this->response($result->toArray());
    }

}