<?php

namespace App\Repository\Impl;

use App\Lib\ConfigKey;
use App\Lib\CpApi;
use App\Lib\CpEncrypt;
use App\Lib\Facade\Cache;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\NoSqlKey;
use Hyperf\DbConnection\Db;

class SiteRepository
{

    /**
     * 获取所有对象站点的receive key.
     *
     * @return array
     */
    public static function initSiteKeys(): array
    {
        $siteHosts    = Db::table('cp_host')->select(['site_bid', 'api_host'])->where('is_enabled', 1)->get()->toArray();
        $fetchedSites = [];
        $map          = [];

        foreach ($siteHosts as $each) {
            $url = trim($each->api_host);
            if ($url === '') {
                continue;
            }

            /** @var \App\Repository\Impl\ConfigRepository $configRepo */
            $configRepo = Container::get(ConfigRepository::class);

            /** @var \App\Lib\CpApi $cpApi */
            $cpApi = Container::make(CpApi::class, ['siteBid' => $each->site_bid]);
            $key   = $cpApi->getReceiveKey($configRepo->getByKey(ConfigKey::MasterKey));
            if ($key !== '') {
                $masterKey = $configRepo->getByKey(ConfigKey::MasterKey);
                $decodeKey = CpEncrypt::authCode($key, 'DECODE', 30, $masterKey);
                Log::cli(sprintf('site: %s - k: %s', $each->site_bid, $key), 'init_site_key');
                $map[$each->site_bid] = $decodeKey;
                $fetchedSites[]       = $each->site_bid;
            }
        }

        if ( ! empty($map)) {
            Cache::set(NoSqlKey::siteRKeyMap(), $map, Cache::randTTL(86400, 7));
        }

        return $fetchedSites;
    }

    /**
     * @param  bool  $useCache
     *
     * @return array
     */
    public function getSiteHostMap(bool $useCache = true): array
    {
        $key = NoSqlKey::siteHost();
        if ($useCache && Cache::has($key)) {
            return Cache::get($key);
        }

        $hosts   = Db::table('cp_host')->select(['site_bid', 'api_host'])->get()->toArray();
        $hostMap = array_column($hosts, null, 'site_bid');
        Cache::set($key, $hostMap, Cache::randTTL());

        return $hostMap;
    }

    /**
     * 取单站的receive key
     *
     * @param  string  $siteBid
     *
     * @return string
     */
    protected function fetchRKeyBySite(string $siteBid): string
    {
        /** @var \App\Repository\Impl\ConfigRepository $configRepo */
        $configRepo = Container::get(ConfigRepository::class);
        $masterKey  = $configRepo->getByKey(ConfigKey::MasterKey);

        /** @var \App\Lib\CpApi $cpApi */
        $cpApi      = Container::make(CpApi::class, ['siteBid' => $siteBid]);
        $encodeRKey = $cpApi->getReceiveKey($masterKey);
        if (empty($encodeRKey)) {
            return '';
        }

        return CpEncrypt::authCode($encodeRKey, 'DECODE', 30, $masterKey);
    }

    /**
     * @param  string  $siteBid
     *
     * @return string
     */
    public function getSiteRKey(string $siteBid): string
    {
        $key          = NoSqlKey::siteRKeyMap();
        $keyMapExists = Cache::has($key);
        if ( ! $keyMapExists) {
            $map  = [];
            $rKey = $this->fetchRKeyBySite($siteBid);
            if (empty($rKey)) {
                Log::error(sprintf('(1)获取站点receive key失败! site: %s', $siteBid), 'get_remote_site_r_key_failed');

                return '';
            }

            $map[$siteBid] = $rKey;
            Cache::set($key, $map, Cache::randTTL(86400, 7));

            return $rKey;
        }

        $map = (array)Cache::get($key);
        if ( ! isset($map[$siteBid])) {
            $rKey = $this->fetchRKeyBySite($siteBid);
            if (empty($rKey)) {
                Log::error(sprintf('(2)即時获取站点receive key失败! site: %s', $siteBid), 'get_remote_site_r_key_failed');

                return '';
            }

            $map[$siteBid] = $rKey;
            Cache::set($key, $map, Cache::randTTL(86400, 7));

            return $rKey;
        }

        return $map[$siteBid];
    }

}