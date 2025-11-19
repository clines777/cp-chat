<?php

namespace App\Lib;

use App\Lib\Facade\Log;
use App\Model\GroupUser;
use Swoole\WebSocket\Server;

use function Hyperf\Config\config;

class Helper
{

    /**
     * 取例外详情.
     *
     * @param  \Throwable|null  $e
     *
     * @return string
     */
    public static function getExpDetails(?\Throwable $e): string
    {
        if ($e === null) {
            return "";
        }

        return sprintf('Exception: File: %s, Line: %d, Msg: %s', $e->getFile(), $e->getLine(), $e->getMessage());
    }

    /**
     * 简单封装下, 省得每次都要写一堆boilerplate code
     *
     * @param  \Swoole\WebSocket\Server  $server
     * @param  int                       $fd
     * @param  \App\Lib\MsgPayload       $payload
     *
     * @return void
     */
    public static function push(Server $server, int $fd, MsgPayload $payload): void
    {
        if ($fd <= 0) {
            return;
        }

        $json = $payload->jsonSerialize();
        try {
            if ($server->isEstablished($fd)) {
                $server->push($fd, $json);
            }
        } catch (\Throwable $e) {
            Log::error(sprintf('Helper::push() 推送消息失败! 讯息: %s, fd: %s, msg: %s', self::getExpDetails($e), $fd, $json), 'msg_push_err');
        }
    }

    /**
     * 正常发onClose后关闭. 不要用$server->disconnect, 因为是在TCP层的行为, 不会触发onClose
     *
     * @param  \Swoole\WebSocket\Server  $server
     * @param  int                       $fd
     * @param  \App\Lib\MsgPayload|null  $payload
     *
     * @return void
     */
    public static function disconnect(Server $server, int $fd, ?MsgPayload $payload = null): void
    {
        if ($server->isEstablished($fd)) {
            if ($payload instanceof MsgPayload) {
                $server->push($fd, $payload->jsonSerialize());
            }
            $server->disconnect($fd);
        }
    }

    /**
     * 字元过滤.
     *
     * @param  string  $str
     *
     * @return string
     */
    public static function secureStr(string $str): string
    {
        return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * 是否为测试环境.
     *
     * @return bool
     */
    public static function isTestEnv(): bool
    {
        $val = config('sys.app_env');

        $val = strtolower($val);

        return $val === SysConst::EnvTest;
    }

    /**
     * 是否为本地开发.
     *
     * @return bool
     */
    public static function isDevEnv(): bool
    {
        $val = config('sys.app_env');

        return strtolower($val) === SysConst::EnvDev;
    }

    /**
     * 修饰用户名.
     *
     * @param  string  $username
     *
     * @return string
     */
    public static function maskUsername(string $username): string
    {
        if (empty($username)) {
            return '';
        }

        $namePrefix = '****';
        if (strlen($username) > 4) {
            $name = $namePrefix.substr($username, -4);
        } else {
            $name = $namePrefix.$username;
        }

        return $name;
    }

    /**
     * 生成user code
     *
     * @param  string  $base     基底标示字串, 通常是用户名.
     * @param  string  $siteBid  各站编号.
     * @param  int     $len
     *
     * @return string
     */
    public static function genUserCode(string $base, string $siteBid, int $len = 10): string
    {
        $combine = $base.substr($siteBid, -2).(int)microtime(true).(int)round(microtime(true) * 1000);
        $hash    = sprintf('%u', crc32($combine));
        $num     = (int)$hash % 10000000000;

        return str_pad((string)$num, $len, '0', STR_PAD_LEFT);
    }

    /**
     * hash一維陣列.
     *
     * @param  array   $array
     * @param  string  $algorithm
     *
     * @return string
     */
    public static function array_hash(array $array, string $algorithm = 'md5'): string
    {
        sort($array);
        $json = json_encode($array);
        $hash = '';
        switch (strtolower($algorithm)):
            case 'md5':
                $hash = md5($json);
                break;
            case 'sha1':
                $hash = sha1($json);
                break;
        endswitch;

        return $hash;
    }

    /**
     * 組建聊天用戶頭銜
     *
     * @param  int  $roleType
     * @param  int  $userLevel
     *
     * @return string
     */
    public static function buildChatUserTitle(int $roleType, int $userLevel = 0): string
    {
        return match ($roleType) {
            GroupUser::RoleUser, GroupUser::RoleQuit => 'Lv'.$userLevel,
            GroupUser::RoleMod => '群管理',
            GroupUser::RoleOwner => '群主',
            default => ''
        };
    }

    /**
     * 字串是否包含url
     *
     * @param  string  $text
     *
     * @return bool
     */
    public static function containsUrl(string $text): bool
    {
        $text = strtolower($text);
        // fast check
        if (stripos($text, 'http') === false && stripos($text, 'https') === false && stripos($text, 'www.') === false) {
            return false;
        }

        // regex check
        static $pattern = '/(?:https?:\/\/|www\.)[a-z0-9\-]+(\.[a-z0-9\-]+)+([\/?#][^\s]*)?/i';

        return (bool)preg_match($pattern, $text);
    }

}