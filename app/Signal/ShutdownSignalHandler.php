<?php

namespace App\Signal;

use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Facade\Redis;
use App\Lib\Helper;
use App\Lib\RStream;
use App\Repository\Impl\ConfigRepository;
use Hyperf\Signal\Annotation\Signal;
use Hyperf\Signal\SignalHandlerInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;

use function Hyperf\Config\config;

#[Signal(priority: 1)]
class ShutdownSignalHandler implements SignalHandlerInterface
{

    public function listen(): array
    {
        return [
            [SignalHandlerInterface::WORKER, SIGTERM],
        ];
    }

    public function handle(int $signal): void
    {
        $lockKey = 'service_close_lock';
        if ( ! Redis::lock($lockKey, 10)) {
            return;
        }

        try {
            Log::cli('开始服务关闭流程...', 'start_service_close');
            /** @var ConfigRepository $configRepo */
            $configRepo = Container::get(ConfigRepository::class);
            $configRepo->setServerState(false);//关闭服务状态

            $wg = new WaitGroup();
            $wg->add();

            Coroutine::create(static function () use ($wg) {
                try {
                    RStream::push(RStream::TypeNotifyServiceClose, ['t' => time()]);//通知所有client服务即将关闭
                } catch (\Throwable $e) {
                    Log::error('通知即将关闭异常: '.Helper::getExpDetails($e), 'send_close_notify_err');
                }
            });

            $maxWait = (int)config('sys.close_wait_sec', 5);//刻意等待5秒, 让转发通知有足够时间发完
            $wg->wait($maxWait);
        } catch (\Throwable $e) {
            Log::error('关停服务异常! 讯息: '.Helper::getExpDetails($e), 'close_service_err');
        }
        finally {
            Redis::unlock($lockKey);
        }
    }

}
