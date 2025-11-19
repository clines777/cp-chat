<?php

namespace App\Repository\Impl;

use App\Lib\ConfigKey;
use App\Lib\Facade\Cache;
use App\Lib\NoSqlKey;
use Hyperf\DbConnection\Db;

/**
 * 动态配置
 */
class ConfigRepository
{

    public string $table = 'config';

    /**
     * 紅包活動是否啟用.
     *
     * @return bool
     */
    public function isBonusOpen(): bool
    {
        $val = $this->getByKey(ConfigKey::BonusOpen);

        return (int)$val > 0;
    }

    /**
     * 取系统群封面图
     *
     * @return string
     */
    public function getSysGroupCoverPath(): string
    {
        $keys   = [ConfigKey::DefaultSysGroupCover, ConfigKey::CdnUrl];
        $values = $this->getByKeys($keys);

        $cdnUrl = isset($values[ConfigKey::CdnUrl]) ? trim($values[ConfigKey::CdnUrl]) : '';
        $path   = isset($values[ConfigKey::DefaultSysGroupCover]) ? trim($values[ConfigKey::DefaultSysGroupCover]) : '';

        return $cdnUrl.$path;
    }

    /**
     * @param  string  $key
     *
     * @return string
     */
    public function getByKey(string $key): string
    {
        $map = $this->getMap();

        return isset($map[$key]) ? (string)$map[$key] : '';
    }

    /**
     * @param  array  $keys
     *
     * @return array
     */
    public function getByKeys(array $keys): array
    {
        $key = NoSqlKey::config();
        if (Cache::has($key)) {
            $configMap = Cache::get($key);
        } else {
            $configMap = $this->getMap();
        }

        $values = [];
        foreach ($keys as $k) {
            $values[$k] = isset($configMap[$k]) ? trim($configMap[$k]) : '';
        }

        return $values;
    }

    /**
     * 取全部config
     *
     * @param  bool  $useCache
     *
     * @return array
     */
    public function getMap(bool $useCache = true): array
    {
        $key = NoSqlKey::config();
        if ($useCache && Cache::has($key)) {
            return Cache::get($key);
        }

        $list = Db::table($this->table)->where('is_open', 1)->get()->toArray();
        $map  = array_column($list, 'value', 'key');
        Cache::set($key, $map, Cache::randTTL());

        return $map;
    }

    /**
     * @param  bool  $isOn
     *
     * @return void
     */
    public function setServerState(bool $isOn = true): void
    {
        $val = $isOn ? 1 : 0;
        Db::table($this->table)->where('key', ConfigKey::ServerOn)->update(['value' => $val]);
        Cache::del(NoSqlKey::serverOpen());
    }

    /**
     * server是否开放.
     *
     * @return bool
     */
    public function isServerOpen(): bool
    {
        $isOn = false;
        $key  = NoSqlKey::serverOpen();
        if (Cache::has($key)) {
            $isOn = Cache::get($key);

            return (int)$isOn > 0;
        }

        $data = Db::table($this->table)->where('key', ConfigKey::ServerOn)->select('value')->first();
        if ( ! empty($data)) {
            $isOn = (int)$data->value > 0;
            Cache::set($key, $isOn, Cache::randTTL());
        }

        return $isOn;
    }

    /**
     * 取手动操作Token
     *
     * @return string
     */
    public function getManualToken(): string
    {
        $key = NoSqlKey::ManualToken();
        if (Cache::has($key)) {
            return Cache::get($key);
        }

        $data = Db::table($this->table)->where('key', ConfigKey::ManualToken)->select('value')->first();
        if ( ! empty($data)) {
            Cache::set($key, $data->value, Cache::randTTL());
        }

        return $data->value;
    }

}