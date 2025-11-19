<?php

declare(strict_types=1);

namespace App\Process;

use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Helper;
use App\Lib\MsgType;
use App\Lib\RStream;
use App\Service\Impl\LuckyMoneyService;
use App\Service\Impl\NotifyService;
use App\Service\Impl\SysChatService;
use Hyperf\Process\Annotation\Process;
use Swoole\Server as SwooleServer;

use function Hyperf\Config\config;

#[Process(nums: 1, name: "NotifyProcess", enableCoroutine: true)]
class NotifyProcess extends WsBaseProcess
{

    public function handle(): void
    {
        $stream   = RStream::NotifyStream;
        $redis    = RStream::getRedis();
        $group    = sprintf('server:%d:chat:%s:stream', config('sys.server_id'), $stream);
        $consumer = uniqid('n_worker_', true);

        try {
            $redis->xgroup('CREATE', $stream, $group, '0', true);
        } catch (\Throwable $e) {
            Log::error("通知队列管道创建异常! 讯息: ".Helper::getExpDetails($e), 'notify_process_init_failed');
        }

        /** @var SwooleServer $server */
        $server = $this->websocketServer->getServer();
        while (true) {
            $msgFrame = $redis->xReadGroup($group, $consumer, [$stream => '>'], 50, 500);
            //            Log::cli($stream.":完整推送数据:".json_encode($msgFrame, JSON_UNESCAPED_UNICODE));
            $msgList = $msgFrame[$stream] ?? [];
            if (empty($msgList)) {
                continue;
            }

            foreach ($msgList as $msgId => $msg) {
                try {
                    Log::info($stream.":推送数据:".json_encode($msg, JSON_UNESCAPED_UNICODE), 'notify_process_input');
                    $msgType = isset($msg[RStream::MsgTypeField]) ? (int)($msg[RStream::MsgTypeField]) : -1;
                    $this->process($server, $msgType, $msg);
                } catch (\Throwable $e) {
                    Log::error("通知队列管道消费异常!".Helper::getExpDetails($e), 'notify_process_err');
                }
                finally {
                    if ( ! empty($msgId)) {
                        $redis->xack($stream, $group, [$msgId]);
                    }
                }
            }
        }
    }

    /**
     * 分发.
     *
     * @param  mixed  $server
     * @param  int    $msgType
     * @param  array  $msg
     *
     * @return void
     */
    protected function process(mixed $server, int $msgType, mixed $msg): void
    {
        /** @var NotifyService $notifyService */
        $notifyService = Container::get(NotifyService::class);
        switch ($msgType):
            case RStream::TypeBanUser:
                $notifyService->notifyUserBanned($server, $msg);
                break;
            case RStream::TypeUnbanUser:
                $notifyService->notifyUserUnbanned($server, $msg);
                break;
            case RStream::TypeKickUser:
                $notifyService->notifyUserKicked($server, $msg);
                break;
            case RStream::TypeDismissGroup:
                $notifyService->notifyGroupDismiss($server, $msg);
                break;
            case RStream::TypeUserQuitGroup:
                $notifyService->notifyUserQuitGroup($server, $msg);
                break;
            case RStream::TypeLuckyMoneyStart:
                $notifyService->notifyLmStart($server, $msg);
                break;
            case RStream::TypeLmSendCpFunding:
                /** @var LuckyMoneyService $luckyMoneyService */ $luckyMoneyService = Container::get(LuckyMoneyService::class);
                $luckyMoneyService->sendCpFunding($msg);
                break;
            case RStream::TypeSendSysChat:
                /** @var SysChatService $sysChatService */ $sysChatService = Container::get(SysChatService::class);
                $sysChatService->notifySysChat($server, $msg);
                break;
            case RStream::TypeNotifyGroupLmClose:
                $notifyService->notifyGroupLmClose($server, $msg);
                break;
            case RStream::TypeNotifySysLmClose:
                $notifyService->notifySysLmClose($server, $msg);
                break;
            case RStream::TypeGlobalBan:
                $notifyService->notifyGlobalBan($server, $msg, MsgType::GlobalBanAck);
                break;
            case RStream::TypeGlobalUnban:
                $notifyService->notifyGlobalBan($server, $msg, MsgType::GlobalUnbanAck);
                break;
            case RStream::TypeNotifyDelChat:
                $notifyService->notifyDelChat($server, $msg);
                break;
            case RStream::TypeNotifyGroupState:
                $notifyService->notifyGroupState($server, $msg);
                break;
            case RStream::TypeNotifyPinChat:
                $notifyService->notifyPinChat($server, $msg);
                break;
            case RStream::TypeNotifyUnpinChat:
                $notifyService->notifyUnpinChat($server, $msg);
                break;
            case RStream::TypeNotifyInGroupNewChat:
                $notifyService->notifyGroupNewChat($server, $msg);
                break;
            case RStream::TypeNotifyServiceClose:
                $notifyService->notifyServiceClose($server, $msg);
                break;
            case RStream::TypeNotifyMyGroupLastChat:
                $notifyService->notifyMyGroupLastChat($server, $msg);
                break;
            case RStream::TypeNotifyUserRoleChange:
                $notifyService->notifyInGroupRoleChange($server, $msg);
        endswitch;
    }

}
