<?php

namespace App\CronTask;

use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Helper;
use App\Service\Impl\LuckyMoneyService;
use Hyperf\Crontab\Annotation\Crontab;

#[Crontab(rule: "0 * * * * *", name: "LuckyMoneyTask", onOneServer: true, callback: "execute", memo: "红包超时自动结算", enable: true, timezone: "Asia/Shanghai")]
class LuckyMoneyTask
{

    /**
     * @return void
     */
    public function execute(): void
    {
        /** @var LuckyMoneyService $luckyMoneyService */
        $luckyMoneyService = Container::get(LuckyMoneyService::class);
        try {
            $luckyMoneyService->settleExpireLuckyMoney();
        } catch (\Throwable $e) {
            Log::cli("红包一般超时结算处理异常! 讯息: ".Helper::getExpDetails($e));
        }
    }

}