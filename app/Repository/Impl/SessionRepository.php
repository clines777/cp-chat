<?php

namespace App\Repository\Impl;

use App\Lib\Facade\Container;
use App\Lib\Facade\Redis;
use App\Lib\NoSqlKey;
use App\Lib\Scene;

use function Hyperf\Config\config;

/**
 * 用户Session操作.
 */
class SessionRepository
{

    /**
     * 设置聊天用户session
     *
     * @param  int     $fd        fd
     * @param  string  $infoJson  用户数据json
     * @param  int     $ttl       ttl
     *
     * @return bool
     */
    public function rMakeSession(int $fd, string $infoJson, int $ttl): bool
    {
        if (empty($infoJson)) {
            return false;
        }

        $key = NoSqlKey::sessionKey($fd);

        return Redis::set($key, $infoJson, $ttl);
    }

    /**
     * 更新session栏位.
     *
     * @param  int    $fd
     * @param  array  $fields  更新栏位key value pair
     * @param  int    $ttl
     *
     * @return array
     */
    public function rPatchSession(int $fd, array $fields, int $ttl = -1): array
    {
        if ($ttl < 1) {
            $ttl = config('business.session_ttl');
        }

        $session = $this->rGetSession($fd);
        if (empty($session)) {
            return [];
        }

        if ( ! empty($fields)) {
            foreach ($fields as $key => $val) {
                $session[$key] = $val;
            }
        }

        $ok = (bool)$this->rMakeSession($fd, json_encode($session), $ttl);
        if ( ! $ok) {
            return [];
        }

        return $this->rGetSession($fd);
    }

    /**
     * 用chat token取出用户基本资讯.
     *
     * @param  int  $fd
     *
     * @return array
     */
    public function rGetSession(int $fd): array
    {
        $session = [];
        $key     = NoSqlKey::sessionKey($fd);
        if (Redis::has($key)) {
            $json = Redis::get($key);
            if ( ! empty($json)) {
                $session = json_decode($json, true);
            }
        }

        if (empty($session)) {
            $session = [];
        }

        return $session;
    }

    /**
     * session延时.
     *
     * @param  int  $fd
     * @param  int  $ttl
     *
     * @return array
     */
    public function rExtendSession(int $fd, int $ttl = 300): array
    {
        if ($ttl < 1) {
            $ttl = config('business.session_ttl');
        }
        $key = NoSqlKey::sessionKey($fd);
        if (Redis::has($key)) {
            Redis::ttl($key, $ttl);
            $jsonStr = Redis::get($key);
        }

        $data = [];
        if ( ! empty($jsonStr)) {
            $data = json_decode($jsonStr, true);
        }

        return (array)$data;
    }

    public function rSaveApiToken(string $token, string $json, int $ttl = 1): bool
    {
        if ($ttl < 1) {
            $ttl = config('business.session_ttl');
        }

        if (empty($json)) {
            return false;
        }

        $key = NoSqlKey::apiTokenKey($token);

        return Redis::set($key, $json, $ttl);
    }

    /**
     * @param  string  $apiToken
     * @param  int     $apiTokenTtl
     *
     * @return bool
     */
    public function rExtendApiToken(string $apiToken, mixed $apiTokenTtl): bool
    {
        $key = NoSqlKey::apiTokenKey($apiToken);
        if (Redis::has($key)) {
            Redis::ttl($key, $apiTokenTtl);
        }

        return true;
    }

    public function rExtendResumeToken(string $resumeToken, int $resumeTtl): bool
    {
        $key = NoSqlKey::resumeTokenKey($resumeToken);
        if (Redis::has($key)) {
            Redis::ttl($key, $resumeTtl);
        }

        return true;
    }

    /**
     * @param  int  $userId
     * @param  int  $ttl
     *
     * @return bool
     */
    public function rExtendUidFdMapping(int $userId, int $ttl): bool
    {
        $key = NoSqlKey::userIdToFdKey($userId);
        if (Redis::has($key)) {
            Redis::ttl($key, $ttl);
        }

        return true;
    }

    /**
     * @param  string  $token
     *
     * @return array
     */
    public function rGetApiTokenInfo(string $token): array
    {
        if (empty($token)) {
            return [];
        }

        $data = [];
        $key  = NoSqlKey::apiTokenKey($token);
        $json = Redis::get($key);
        if ( ! empty($json)) {
            $data = json_decode($json, true);
        }

        return (array)$data;
    }

    /**
     * @param  int  $userId
     *
     * @return int
     */
    public function rGetExistsUserFd(int $userId): int
    {
        $key = NoSqlKey::userIdToFdKey($userId);
        if ( ! Redis::has($key)) {
            return 0;
        }

        return (int)Redis::get($key);
    }

    /**
     * @param  int  $fd
     *
     * @return int
     */
    public function rRefreshSessionTokens(int $fd): int
    {
        $sessionTtl = config('business.session_ttl');
        $resumeTtl  = config('business.resume_token_ttl');

        $session = $this->rExtendSession($fd, $sessionTtl);
        if ( ! empty($session['api_token'])) {
            $this->rExtendApiToken($session['api_token'], $sessionTtl);
        }
        if ( ! empty($session['id'])) {
            $this->rExtendUidFdMapping($session['id'], $sessionTtl);
        }

        if ( ! empty($session['resume_token'])) {
            $this->rExtendResumeToken($session['resume_token'], $resumeTtl);
        }

        return $session['id'] ?? 0;
    }

    public function rSaveResumeToken(string $resumeToken, array $resumeInfo, int $ttl): bool
    {
        if (empty($resumeInfo)) {
            return false;
        }

        $key = NoSqlKey::resumeTokenKey($resumeToken);

        return Redis::set($key, json_encode($resumeInfo), $ttl);
    }

    public function rMakeUserIdToFdMapping(int $userId, int $fd, int $ttl): bool
    {
        $key = NoSqlKey::userIdToFdKey($userId);

        return Redis::set($key, $fd, $ttl);
    }

    /**
     * 清除目标用户跟fd对象的session相关数据.
     *
     * @param  int  $fd
     *
     * @return bool
     */
    public function rCleanUserSessions(int $fd): bool
    {
        $sessionKey = NoSqlKey::sessionKey($fd);
        $session    = [];
        //主要session
        if (Redis::has($sessionKey)) {
            $session = Redis::get($sessionKey);
            if ( ! empty($session)) {
                $session = json_decode($session, true);
            }
            Redis::del($sessionKey);
        }

        //api token
        if ( ! empty($session['api_token'])) {
            $apiTokenKey = NoSqlKey::apiTokenKey($session['api_token']);
            Redis::del($apiTokenKey);
        }

        //        //断线重连Token, 当前机制下先不删除, 避免又多打一轮
        //        if ( ! empty($session['resume_token'])) {
        //            $resumeTokenKey = NoSqlKey::resumeTokenKey($session['resume_token']);
        //            Redis::del($resumeTokenKey);
        //        }

        //site online state
        if ( ! empty($session['id'])) {
            $this->rDelSiteOnline($session['site_bid'], $session['id']);
        }

        //删除 user id to fd mapping
        if ( ! empty($session['id'])) {
            $uidFdMappingKey = NoSqlKey::userIdToFdKey($session['id']);
            if (Redis::has($uidFdMappingKey)) {
                Redis::del($uidFdMappingKey);
            }
        }

        //群组在线
        $groupId = Scene::fetchGroupId($session);
        if ( ! empty($groupId)) {
            /** @var \App\Repository\Impl\GroupUserRepository $groupUserRepo */
            $groupUserRepo = Container::get(GroupUserRepository::class);
            $groupUserRepo->rDelOnlineUser($groupId, $session['id']);
        }

        $session = null;

        return true;
    }

    /**
     * 设置用户当前所在场景
     *
     * @param  int     $fd
     * @param  string  $scene
     * @param  int     $groupId
     *
     * @return array
     */
    public function rSetScene(int $fd, string $scene, int $groupId = 0): array
    {
        return $this->rPatchSession($fd, Scene::buildScene($scene, $groupId));
    }

    /**
     * @param  int  $fd
     *
     * @return array
     */
    public function rGetScene(int $fd): array
    {
        $session = $this->rGetSession($fd);
        if (empty($session['scene'])) {
            return [];
        }

        return $session['scene'];
    }

    /**
     * 设置站台在线用户.
     *
     * @param  string  $siteBid
     * @param  array   $userInfo
     *
     * @return bool
     */
    public function rSetSiteOnline(string $siteBid, array $userInfo): bool
    {
        $key = NoSqlKey::siteMap($siteBid);
        $val = Redis::instance()->hSet(
            $key,
            (string)$userInfo['id'],
            json_encode(
                ['user_id' => (int)$userInfo['id'], 'ext_member_id' => (int)$userInfo['ext_member_id'], 'ext_username' => $userInfo['ext_username']],
            ),
        );

        if ($val === false) {
            return false;
        }

        return true;
    }

    /**
     * 删除站台在线用户
     *
     * @param  string  $siteBid
     * @param  string  $userId
     *
     * @return bool
     */
    public function rDelSiteOnline(string $siteBid, string $userId): bool
    {
        $key = NoSqlKey::siteMap($siteBid);
        $val = Redis::instance()->hDel($key, $userId);
        if ($val === false) {
            return false;
        }

        return true;
    }

    /**
     * 取站台全部在线.
     *
     * @param  string  $siteBid
     *
     * @return array
     */
    public function rGetSiteOnlineAll(string $siteBid): array
    {
        $key  = NoSqlKey::siteMap($siteBid);
        $list = Redis::instance()->hGetAll($key);
        if (empty($list)) {
            return [];
        }

        $users = [];
        foreach ($list as $json) {
            $user = json_decode($json, true);
            if ( ! empty($user) && is_array($user)) {
                $users[] = $user;
            }
        }

        return $users;
    }

    /**
     * 以user id取session
     *
     * @param  int  $userId
     *
     * @return array
     */
    public function rGetSessionByUid(int $userId): array
    {
        $fd = $this->rGetExistsUserFd($userId);
        if (empty($fd) || $fd <= 0) {
            return [];
        }

        return $this->rGetSession($fd);
    }

    /**
     * 取断线重连Token资讯.
     *
     * @param  string  $resumeToken
     *
     * @return array
     */
    public function rGetResumeTokenInfo(string $resumeToken): array
    {
        $key = NoSqlKey::resumeTokenKey($resumeToken);
        if ( ! Redis::has($key)) {
            return [];
        }

        $json = Redis::get($key);
        if (empty($json)) {
            return [];
        }

        return json_decode($json, true);
    }

    /**
     * @param  string  $key
     *
     * @return bool
     */
    public function rDel(string $key): bool
    {
        return (bool)Redis::del($key);
    }

}