<?php

namespace App\Process;

use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Helper;
use App\Lib\RStream;
use App\Service\Impl\SysChatService;
use Hyperf\Process\Annotation\Process;

use function Hyperf\Config\config;

/**
 * 批量写入队列, 防止批量并发写入同一张表引起deadlock.
 */
#[Process(nums: 1, name: "BatchProcess", enableCoroutine: true)]
class BatchProcess extends WsBaseProcess
{

    public function handle(): void
    {
        $stream   = RStream::BatchStream;
        $redis    = RStream::getRedis(RStream::BatchStream);
        $group    = sprintf('server:%d:chat:%s:stream', config('sys.server_id'), $stream);
        $consumer = uniqid('b_worker_', true);

        try {
            $redis->xgroup('CREATE', $stream, $group, '0', true);
        } catch (\Throwable $e) {
            Log::error("批量队列管道创建异常! 讯息: ".Helper::getExpDetails($e), 'batch_process_init_failed');
        }

        while (true) {
            $msgFrame = $redis->xReadGroup($group, $consumer, [$stream => '>'], 20, 500);
            $msgList  = $msgFrame[$stream] ?? [];
            if (empty($msgList)) {
                continue;
            }

            foreach ($msgList as $msgId => $msg) {
                try {
                    //                    Log::info($stream.":推送数据:".json_encode($msg, JSON_UNESCAPED_UNICODE), 'batch_info');
                    $msgType = isset($msg[RStream::MsgTypeField]) ? (int)($msg[RStream::MsgTypeField]) : -1;
                    $this->process($msgType, $msg);
                } catch (\Throwable $e) {
                    Log::error("批量队列管道消费异常!".Helper::getExpDetails($e), 'batch_process_err');
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
     * @param  int    $msgType
     * @param  mixed  $msg
     *
     * @return void
     */
    protected function process(int $msgType, mixed $msg): void
    {
        switch ($msgType):
            case RStream::TypeBatchSysChat:
                /** @var SysChatService $sysChatService */ $sysChatService = Container::get(SysChatService::class);
                $sysChatService->batchAddSysChat($msg);
                break;
            case RStream::TypeBatchSysLmUser:
                /** @var SysChatService $sysChatService */ $sysChatService = Container::get(SysChatService::class);
                $sysChatService->batchAddSysLmUser($msg);
                break;
        endswitch;
    }

}