<?php

namespace App\Repository\Impl;

use App\Lib\Facade\Cache;
use App\Lib\NoSqlKey;
use App\Model\Avatar;
use Hyperf\DbConnection\Db;
use stdClass;

class AvatarRepository
{

    public string $table = 'avatar';

    /**
     * 取默认头像.
     *
     * @return list<stdClass>
     */
    public function getByType(int $type): array
    {
        return Db::table($this->table)->where('type', $type)->get()->toArray();
    }

    /**
     * 取全部(不含admin)
     *
     * @return list<stdClass>
     */
    public function getAll(): array
    {
        return Db::table($this->table)->where('type', Avatar::TypeUser)->select(['id', 'filename'])->get()->toArray();
    }

    /**
     * get map with id key.
     *
     * @return array
     */
    public function getMap(): array
    {
        $key = NoSqlKey::avatarMap();
        if (Cache::has($key)) {
            return Cache::get($key);
        }

        $map  = [];
        $list = $this->getAll();
        if ( ! empty($list)) {
            $map = array_column($list, 'filename', 'id');
            Cache::set($key, $map, Cache::randTTL());
        }

        return $map;
    }

    /**
     * 取头像数据.
     *
     * @param  mixed  $id
     *
     * @return array
     */
    public function getById(mixed $id): array
    {
        return (array)Db::table($this->table)->where('id', $id)->first();
    }

    /**
     * 取系统消息头像
     *
     * @return string
     */
    public function getSysAvatarPath(): string
    {
        $key = NoSqlKey::sysAvatar();
        if (Cache::has($key)) {
            return Cache::get($key);
        }

        $rows = $this->getByType(Avatar::TypeAdmin);
        if (empty($rows[0]->filename)) {
            return '';
        }

        Cache::set($key, (string)$rows[0]->filename, Cache::randTTL());

        return $rows[0]->filename;
    }

}