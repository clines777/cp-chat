<?php

namespace App\Controller;

use App\Lib\CommonResult;
use App\Lib\ConfigKey;
use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Helper;
use App\Lib\Scene;
use App\Middleware\AuthTokenMiddleware;
use App\Repository\Impl\ConfigRepository;
use App\Repository\Impl\GroupUserRepository;
use App\Repository\Impl\SessionRepository;
use App\Service\Impl\ChatService;
use App\Service\Impl\SysChatService;
use Hyperf\Context\Context;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * APP內聊天相關
 */
#[Controller(prefix: "/app/chat")]
#[Middleware(AuthTokenMiddleware::class)]
class AppChatController extends AbstractController
{

    /**
     * 拉取歷史訊息.
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[GetMapping(path: "history")]
    public function getHistory(ServerRequestInterface $request): ResponseInterface
    {
        $user   = Context::get('user');
        $params = (array)$request->getQueryParams();

        /** @var ChatService $chatService */
        $chatService = Container::get(ChatService::class);
        try {
            if ( ! isset($params['before_chat_id']) || ! is_numeric($params['before_chat_id'])) {
                return $this->response(CommonResult::invalidParam('参数错误')->toArray());
            }

            /** @var ConfigRepository $configRepo */
            $configRepo = Container::get(ConfigRepository::class);
            /** @var SessionRepository $sessionRepo */
            $sessionRepo = Container::get(SessionRepository::class);
            $session     = $sessionRepo->rGetSessionByUid($user['id']);
            $groupId     = Scene::fetchGroupId($session);
            if (empty($groupId)) {
                return $this->response(CommonResult::make(ErrorCode::ErrInvalidOperate, '用户不在群内')->toArray());
            }

            /** @var GroupUserRepository $groupUserRepo */
            $groupUserRepo = Container::get(GroupUserRepository::class);
            $groupUser     = $groupUserRepo->getGroupUser($user['id'], $groupId);
            $count         = ! empty($params['count']) && $params['count'] > 0 && $params['count'] <= 100
                ? (int)$params['count']
                : (int)$configRepo->getByKey(
                    ConfigKey::GroupLastChatCount,
                );
            $chatList      = $chatService->getHistory($groupId, (int)$params['before_chat_id'], $groupUser['last_visible_chat_id'], $count);
            $result        = CommonResult::success('ok', $chatList);
        } catch (\Throwable $e) {
            Log::error(Helper::getExpDetails($e), 'getChatHistory_err');
            $result = CommonResult::make(ErrorCode::ErrGetChatHistoryFailed, '获取失败');
        }

        return $this->response($result->toArray());
    }

    /**
     * 获取系统群历史讯息.
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    #[GetMapping('sys-history')]
    public function getSysHistory(ServerRequestInterface $request): ResponseInterface
    {
        $user   = Context::get('user');
        $params = (array)$request->getQueryParams();

        /** @var SysChatService $sysChatService */
        $sysChatService = Container::get(SysChatService::class);

        try {
            $result = $sysChatService->getHistory($user['id'], $params);
        } catch (\Throwable $e) {
            Log::error(sprintf('获取系统群历史讯息异常! 参数: %s, 讯息: %s', json_encode($params, 256), Helper::getExpDetails($e)), 'getSysHistory_err');
            $result = CommonResult::error('获取失败');
        }

        return $this->response($result->toArray());
    }

}