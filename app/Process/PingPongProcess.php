<?php

declare(strict_types=1);

namespace App\Process;

use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Helper;
use App\Lib\MsgPayload;
use App\Repository\Impl\SessionRepository;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Process\Annotation\Process;

/**
 * 仅测试用, 由后端ping会有延迟, 实际会让client ping过来
 */
#[Process(nums: 1, name: "PingPongProcess", enableCoroutine: true)]
class PingPongProcess extends WsBaseProcess
{

    /**
     * 只在本地跑, 方便开发
     *
     * @param $server
     *
     * @return bool
     */
    public function isEnable($server): bool
    {
        return Helper::isDevEnv();
    }

    public bool $enableCoroutine = true;

    public function handle(): void
    {
        /** @var SessionRepository $sessionRepo */
        $sessionRepo = Container::get(SessionRepository::class);

        /** @var \Swoole\WebSocket\Server $server */
        $server = $this->websocketServer->getServer();
        while (true) {
            Coroutine::sleep(30);
            foreach ($server->connections as $fd) {
                try {
                    $session = $sessionRepo->rGetSession($fd);
                    if ($server->isEstablished($fd)) {
                        if (empty($session)) {
                            $sessionRepo->rCleanUserSessions($fd);
                            Log::info(sprintf("connection closed, fd: %d", $fd), 'PingPongProcess');
                            Helper::disconnect($server, $fd, MsgPayload::error(ErrorCode::ErrSessionLost));
                        } else {
                            $sessionRepo->rRefreshSessionTokens($fd);
                        }
                    }
                } catch (\Throwable $e) {
                    Log::error('server auto refresh异常! 讯息:'.Helper::getExpDetails($e), 'PingPongProcess_err');
                }
            }
        }
    }

}