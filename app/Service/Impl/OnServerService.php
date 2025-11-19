<?php

namespace App\Service\Impl;

use App\Lib\Facade\Cache;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Facade\Redis;
use App\Lib\RStream;
use App\Repository\Impl\ConfigRepository;

use function Hyperf\Config\config;

class OnServerService
{

    /**
     * 清除redis跟cache缓存
     *
     * @return void
     */
    public function clearStateAndCache(): void
    {
        //详见config/autoload/redis.php配置
        Redis::flushDb(config('redis.default.db'));//清session等易挥发状态

        Cache::clear();//清缓存

        $notifyPool = Redis::instance(Redis::PoolNotify);//清空notify queue
        if ($notifyPool->exists(RStream::NotifyStream)) {
            $notifyPool->xtrim(RStream::NotifyStream, '0');
        }

        Log::cli('onServer事件: redis.default, redis.cache, redis.notify已清除', 'init_clearState');
    }

    /**
     * 检查WS_ID配置.
     *
     * @return void
     */
    public function serverIdMustExists(): void
    {
        $serverId = config('sys.server_id');
        if ($serverId === null) {
            throw new \InvalidArgumentException(
                '环境变数WS_ID未正确配置, 请确保多台Server配置不同的WS_ID, 格式为整数, 依Server数由0开始递增',
            );
        }

        Log::info('当前SERVER_ID:'.$serverId, 'SERVER_ID');
    }

    /**
     * 开启服务状态
     *
     * @return void
     */
    public function enableServerState(): void
    {
        /** @var ConfigRepository $configRepo */
        $configRepo = Container::get(ConfigRepository::class);
        $configRepo->setServerState(true);
    }

}