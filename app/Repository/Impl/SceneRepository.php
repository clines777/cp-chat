<?php

namespace App\Repository\Impl;

use App\Lib\Facade\Redis;
use App\Lib\NoSqlKey;

class SceneRepository
{

    public function addAtMyGroup(int $userId): bool
    {
        $key = NoSqlKey::myGroupUsers();

        return (bool)Redis::instance()->sadd($key, $userId);
    }

    public function removeAtMyGroup(int $userId): bool
    {
        $key = NoSqlKey::myGroupUsers();

        return (bool)Redis::instance()->srem($key, $userId);
    }

    public function getMyGroupUsers(): array
    {
        $key = NoSqlKey::myGroupUsers();

        return (array)Redis::instance()->smembers($key);
    }

    public function existsAtMyGroup(int $userId): bool
    {
        $key   = NoSqlKey::myGroupUsers();
        $redis = Redis::instance();

        return (bool)$redis->sismember($key, $userId);
    }

    public function addAtLobby(int $userId): bool
    {
        $key = NoSqlKey::myGroupUsers();

        return (bool)Redis::instance()->sadd($key, $userId);
    }

    public function removeAtLobby(int $userId): bool
    {
        $key = NoSqlKey::myGroupUsers();

        return (bool)Redis::instance()->srem($key, $userId);
    }

    public function getLobbyUsers(): array
    {
        $key = NoSqlKey::myGroupUsers();

        return (array)Redis::instance()->smembers($key);
    }

    public function existsAtLobby(int $userId): bool
    {
        $key   = NoSqlKey::myGroupUsers();
        $redis = Redis::instance();

        return (bool)$redis->sismember($key, $userId);
    }

}