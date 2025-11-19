<?php

namespace App\Service\Impl;

use App\Lib\CommonResult;
use App\Lib\Facade\Container;
use App\Repository\Impl\SessionRepository;

/**
 *
 */
class StateService
{

    /**
     * 回传伺服器状态数据.
     *
     * @param  array  $param
     *
     * @return \App\Lib\CommonResult
     */
    public function getServerState(array $param): CommonResult
    {
        $param['site_bid'] = isset($param['site_bid']) ? trim($param['site_bid']) : '';
        $onlineCount       = $this->countUserBySite($param['site_bid']);

        return CommonResult::success('ok', ['count' => $onlineCount]);
    }

    /**
     * 统计站点在线人数.
     *
     * @param  string  $siteBid
     *
     * @return int
     */
    public function countUserBySite(string $siteBid): int
    {
        if (empty($siteBid)) {
            return 0;
        }

        /** @var SessionRepository $sessionRepo */
        $sessionRepo = Container::get(SessionRepository::class);
        $siteOnline  = $sessionRepo->rGetSiteOnlineAll($siteBid);

        return count($siteOnline);
    }

}