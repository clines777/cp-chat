<?php

declare(strict_types=1);

namespace App\Listener;

use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Facade\Redis;
use App\Lib\Helper;
use App\Repository\Impl\SiteRepository;
use App\Service\Impl\OnServerService;
use Hyperf\DbConnection\Db;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\MainWorkerStart;

use function Hyperf\Config\config;

#[Listener]
class OnServerStartListener implements ListenerInterface
{

    private static bool $hasRun = false;

    public function listen(): array
    {
        return [
            MainWorkerStart::class,
        ];
    }

    public function process(object $event): void
    {
        /** @var OnServerService $onServerService */
        $onServerService = Container::get(OnServerService::class);

        if (property_exists($event, 'workerId') && $event->workerId === 0 && ! self::$hasRun) {
            //1. DB连线检查
            try {
                Db::table('cp_host')->get();
                Log::info('DB连线正常', 'check_db_conn');
            } catch (\Throwable $eDb) {
                Log::cli('DB连线异常! 讯息: '.Helper::getExpDetails($eDb), 'check_db_conn');
            }

            Log::info("当前env: ".config('sys.app_env'));

            //2. redis 检查
            try {
                Redis::instance(Redis::PoolState)->ping();
                Log::info('Redis state(default) 连线正常', 'check_redis_conn');

                Redis::instance()->ping(Redis::PoolBonus);
                Log::info('Redis bonus 连线正常', 'check_redis_conn');

                Redis::instance()->ping(Redis::PoolCache);
                Log::info('Redis cache 连线正常', 'check_redis_conn');

                Redis::instance()->ping(Redis::PoolNotify);
                Log::info('Redis notify 连线正常', 'check_redis_conn');
            } catch (\Throwable $eRedis) {
                Log::cli('Redis连线异常! 讯息: '.Helper::getExpDetails($eRedis), 'check_redis_conn');
            }

            //3. 清除特定nosql状态
            try {
                $onServerService->clearStateAndCache();
            } catch (\Throwable $e1) {
                Log::error('启动server事件异常! '.$e1->getMessage());
            }

            //4. 检查ws_id配置
            try {
                $onServerService->serverIdMustExists();
            } catch (\Throwable $e2) {
                Log::error("检测环境变数WS_ID配置错误! 讯息:".Helper::getExpDetails($e2));
            }

            //5. 获取各站加解密用的KEY
            try {
                Log::cli('开始获取各站的R key...', 'fetch_cp_r_keys');
                SiteRepository::initSiteKeys();
            } catch (\Throwable $e3) {
                Log::error('初始化各站加解密用KEY异常! 讯息: '.Helper::getExpDetails($e3));
            }

            //6. 开启server状态
            $onServerService->enableServerState();

            self::$hasRun = true;
        }
    }

}
