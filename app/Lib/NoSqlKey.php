<?php

namespace App\Lib;

use function Hyperf\Config\config;

/**
 * 缓存或redis的key.
 */
class NoSqlKey
{

    /**
     * 登入聊天室Token Key
     *
     * @param  string  $token
     *
     * @return string
     */
    public static function loginTokenKey(string $token): string
    {
        return sprintf('token:login:%s', trim($token));
    }

    /**
     * API Token Key
     *
     * @param  string  $token
     *
     * @return string
     */
    public static function apiTokenKey(string $token): string
    {
        return sprintf('token:api:%s', $token);
    }

    /**
     * 断线重连Token Key
     *
     * @param  string  $token
     *
     * @return string
     */
    public static function resumeTokenKey(string $token): string
    {
        return sprintf('token:re:%s', $token);
    }

    /**
     * 聊天室内主要Session
     *
     * @param  int  $fd
     *
     * @return string
     */
    public static function sessionKey(int $fd): string
    {
        $wsId = self::getWsId();

        return sprintf('session:fd:%s:ws:%d', $fd, $wsId);
    }

    /**
     * 聊天群线上名单key.
     *
     * @param  int  $groupId
     *
     * @return string
     */
    public static function groupOnlineKey(int $groupId): string
    {
        $wsId = self::getWsId();

        return sprintf('group:online:%d:ws:%d', $groupId, $wsId);
    }

    /**
     * fd to uid对应
     *
     * @param  int  $userId
     *
     * @return string
     */
    public static function userIdToFdKey(int $userId): string
    {
        $wsId = self::getWsId();

        return sprintf('session:uid_to_fd:%d:ws:%d', $userId, $wsId);
    }

    /**
     * 所有聊天群.
     *
     * @param  string  $siteBid
     *
     * @return string
     */
    public static function allGroup(string $siteBid): string
    {
        return 'all_group:'.$siteBid;
    }

    /**
     * 获取WS_ID
     *
     * @return int|null
     */
    protected static function getWsId(): ?int
    {
        return config('sys.server_id');
    }

    /**
     * 拆分好的紅包數據.
     *
     * @param  int  $luckyMoneyId
     *
     * @return string
     */
    public static function luckyMoneyPackages(int $luckyMoneyId): string
    {
        return sprintf('lucky_money_packs:%d', $luckyMoneyId);
    }

    /**
     * 外部用户未读缓存.
     *
     * @param  string  $siteBid
     * @param  int     $memberId
     *
     * @return string
     */
    public static function extUnread(string $siteBid, int $memberId): string
    {
        return sprintf('ext_user:s:%s:mid:%s', $siteBid, $memberId);
    }

    /**
     * @return string
     */
    public static function siteRKeyMap(): string
    {
        return 'site_key_map';
    }

    /**
     * 成功抢过红包的hash by 用户
     *
     * @param  int  $luckyMoneyId
     *
     * @return string
     */
    public static function luckyMoneyFlag(int $luckyMoneyId): string
    {
        return 'lm_taken:'.$luckyMoneyId;
    }

    /**
     * 跑馬燈數據, 同時只會有一種.
     *
     * @return string
     */
    public static function marquee(): string
    {
        return 'marquee';
    }

    /**
     * 最後一次發送的跑馬燈ID, 用於避免重複發送.
     *
     * @return string
     */
    public static function lastMarqueeId(): string
    {
        return 'last_marquee_id';
    }

    /**
     * avatar map
     *
     * @return string
     */
    public static function avatarMap(): string
    {
        return 'avatar_map';
    }

    /**
     * user map by site
     *
     * @param  string  $siteBid
     *
     * @return string
     */
    public static function siteMap(string $siteBid): string
    {
        $wsId = self::getWsId();

        return sprintf('site_map:%s:ws:%d', $siteBid, $wsId);
    }

    /**
     * 系统导入用户红包.
     *
     * @param  int  $lmId
     *
     * @return string
     */
    public static function sysLmUserMap(int $lmId): string
    {
        return 'sys_lm_user_map:'.$lmId;
    }

    /**
     * 全部config.
     *
     * @return string
     */
    public static function config(): string
    {
        return 'config';
    }

    /**
     * 站点API HOST
     *
     * @return string
     */
    public static function siteHost(): string
    {
        return 'site_host';
    }

    /**
     * 外部查询用户ID
     *
     * @param  string  $siteBid
     * @param  int     $memberId
     *
     * @return string
     */
    public static function extQueryUserId(string $siteBid, int $memberId): string
    {
        return 'ext_query:'.$siteBid.':id:'.$memberId;
    }

    /**
     * server开放中.
     *
     * @return string
     */
    public static function serverOpen(): string
    {
        return 'server_open';
    }

    /**
     * 红包缓存.
     *
     * @param  int  $lmId
     *
     * @return string
     */
    public static function luckyMoneyInfo(int $lmId): string
    {
        return 'lucky_money_info'.$lmId;
    }

    public static function sysAvatar(): string
    {
        return 'sys_avatar';
    }

    /**
     * 聊天列表用户.
     *
     * @return string
     */
    public static function myGroupUsers(): string
    {
        return 'my_group_users';
    }

    /**
     * 手动操作Token
     *
     * @return string
     */
    public static function manualToken(): string
    {
        return 'manual_token';
    }

}