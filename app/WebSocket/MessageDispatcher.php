<?php

namespace App\WebSocket;

use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Helper;
use App\Lib\MsgPayload;
use App\Lib\MsgType;
use App\Repository\Impl\SessionRepository;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Swoole\WebSocket\Server;

/**
 * 事件转发元件.
 */
class MessageDispatcher
{

    /**
     * 所有事件对应表
     *
     * @var array|string[]
     */
    protected array $handlers
        = [
            //心跳
            MsgType::Ping              => \App\WebSocket\Handler\PingHandler::class,
            //用户登入
            MsgType::Login             => \App\WebSocket\Handler\LoginHandler::class,
            //恢复连线
            MsgType::Resume            => \App\WebSocket\Handler\ResumeHandler::class,
            //进入聊天群
            MsgType::EnterGroup        => \App\WebSocket\Handler\EnterGroupHandler::class,
            //离开聊天群
            MsgType::LeaveGroup        => \App\WebSocket\Handler\LeaveGroupHandler::class,
            //发聊天讯息.
            MsgType::SendChat          => \App\WebSocket\Handler\SendChatHandler::class,
            //管理者群内Pin聊天讯息
            MsgType::PinChat           => \App\WebSocket\Handler\PinChatHandler::class,
            //管理者群内unpin聊天讯息
            MsgType::UnpinChat         => \App\WebSocket\Handler\UnpinChatHandler::class,
            //管理者群内操作禁言
            MsgType::BanUser           => \App\WebSocket\Handler\BanHandler::class,
            //管理者群内操作解禁
            MsgType::UnbanUser         => \App\WebSocket\Handler\UnbanHandler::class,
            //管理者群内操作踢群
            MsgType::KickUser          => \App\WebSocket\Handler\KickHandler::class,
            //进入大厅
            MsgType::EnterLobby        => \App\WebSocket\Handler\EnterLobbyHandler::class,
            //进入系统群.
            MsgType::EnterSysGroup     => \App\WebSocket\Handler\EnterSysGroupHandler::class,
            //进入聊天列表
            MsgType::EnterMyGroup      => \App\WebSocket\Handler\EnterMyGroupHandler::class,
            //进入我的资讯页面.
            MsgType::EnterSelfInfo     => \App\WebSocket\Handler\EnterSelfInfoHandler::class,
            //拉取群组历史讯息
            MsgType::GetChatHistory    => \App\WebSocket\Handler\GetChatHistoryHandler::class,
            //更新一般群最新已读
            MsgType::UpdateLastRead    => \App\WebSocket\Handler\UpdateLastReadHandler::class,
            //更新系统群已读.
            MsgType::UpdateSysLastRead => \App\WebSocket\Handler\UpdateSysLastReadHandler::class,
            //取大厅群列表.
            MsgType::GetLobbyGroup     => \App\WebSocket\Handler\GetLobbyGroupHandler::class,
            //取聊天群列表.
            MsgType::GetMyGroup        => \App\WebSocket\Handler\GetMyGroupHandler::class,
            //获取系统群历史讯息
            MsgType::GetSysChatHistory => \App\WebSocket\Handler\GetSysChatHistoryHandler::class,
            //删除群内讯息
            MsgType::DelChat           => \App\WebSocket\Handler\DelChatHandler::class,
        ];

    /**
     * 未验证允许Handlers
     *
     * @var array|\class-string[]
     */
    protected array $nonAuthHandlers
        = [

        ];

    /**
     * 不需要检查登入session的消息类型
     *
     * @var array|true[]
     */
    protected array $checkSessionFreeTypes = [MsgType::Login => true, MsgType::Resume => true];

    /**
     * 委派事件.
     *
     * @param  \Swoole\WebSocket\Server  $server
     * @param  int                       $fd
     * @param  string                    $raw
     *
     * @return void
     */
    public function dispatch(Server $server, int $fd, string $raw): void
    {
        Context::set(Log::LogChanName, Log::LogChanWs);
        try {
            $raw = trim($raw);
            /** @var MsgPayload $payload */
            $payload = ! empty($raw) ? MsgPayload::fromJson($raw) : null;
            if ($payload === null || empty($payload->type)) {
                Log::error(sprintf('MessageDispatcher->dispatch, 传入message或message type为空值! msg: %s', json_encode($payload, 256)), 'payload_err');
                Helper::disconnect($server, $fd, MsgPayload::error(ErrorCode::ErrInvalidPayload, $payload));

                return;
            }

            if ( ! isset($this->handlers[$payload->type])) {
                Helper::disconnect($server, $fd, MsgPayload::error(ErrorCode::ErrInvalidPayload, $payload));

                return;
            }

            /**
             * 需要session的handler直接获取session并传入, 注意!!! Context在不同Coroutine间并不共享
             */
            if ( ! isset($this->checkSessionFreeTypes[$payload->type])) {
                /** @var \App\Repository\Impl\SessionRepository $sessionRepo */
                $sessionRepo = Container::get(SessionRepository::class);
                $session     = $sessionRepo->rGetSession($fd);
                if (empty($session)) {
                    Helper::disconnect($server, $fd, MsgPayload::error(ErrorCode::ErrSessionLost, $payload));
                    Log::error(sprintf('Msg Dispatcher取无session异常! msg type: %s, fd: %s', $payload->type, $fd), 'dispatch_session_err');

                    return;
                }
                Context::set('session', $session);
            }

            /** @var \App\Lib\Interface\MessageHandlerInterface $handler */
            $handler = ApplicationContext::getContainer()->get($this->handlers[$payload->type]);
            $handler->handle($server, $fd, $payload);
        } catch (\Throwable $e) {
            Log::error(sprintf("WS消息处理异常! worker: %s, payload: %s 讯息: %s", $server->getWorkerId(), $raw, Helper::getExpDetails($e)), 'ws_handle_err');
        }
    }

}