<?php

namespace App\Lib;

use App\Lib\Facade\Redis;
use Hyperf\Redis\RedisProxy;
use JsonSerializable;

/**
 * Redis Stream格式定義
 */
class RStream implements JsonSerializable
{

    /**
     * 通用redis stream key.
     */
    public const NotifyStream = 'notify';

    /**
     * 依序批量写入
     */
    public const BatchStream = 'batch';

    /**
     * 彩票后台管理者禁言
     */
    public const TypeBanUser = 1;

    /**
     * 彩票后台管理者解禁.
     */
    public const TypeUnbanUser = 2;

    /**
     * 彩票后台管理者踢群.
     */
    public const TypeKickUser = 3;

    /**
     * 彩票后台管理者解散群组.
     */
    public const TypeDismissGroup = 4;

    /**
     * 对群内用户广播红包讯息.
     */
    public const TypeLuckyMoneyStart = 5;

    /**
     * 红包纪录发给彩票端派奖.
     */
    public const TypeLmSendCpFunding = 6;

    /**
     * 彩票后台管理者发系统消息给用户.
     */
    public const TypeSendSysChat = 7;

    /**
     * 用户主动退群.
     */
    public const TypeUserQuitGroup = 8;

    /**
     * 全域禁言.
     */
    public const TypeGlobalBan = 9;

    /**
     * 全域解禁.
     */
    public const TypeGlobalUnban = 10;

    /**
     * 红包收回.
     */
    public const TypeNotifyGroupLmClose = 11;

    /**
     * 删除讯息.
     */
    public const TypeNotifyDelChat = 12;

    /**
     * 通知更新群状态.
     */
    public const TypeNotifyGroupState = 13;

    /**
     * 通知消息置顶.
     */
    public const TypeNotifyPinChat = 14;

    /**
     * 通知撤销消息置顶.
     */
    public const TypeNotifyUnpinChat = 15;

    /**
     * 广播新聊天讯息.
     */
    public const TypeNotifyInGroupNewChat = 16;

    /**
     * 批量写入系统一般或红包消息.
     */
    public const TypeBatchSysChat = 17;

    /**
     * 批量写入系统红包导入用户
     */
    public const TypeBatchSysLmUser = 18;

    /**
     * 通知系统红包结束.
     */
    public const TypeNotifySysLmClose = 19;

    /**
     * 通知服务关闭.
     */
    public const TypeNotifyServiceClose = 20;

    /**
     * 通知聊天列表群新讯息.
     */
    public const TypeNotifyMyGroupLastChat = 21;

    /**
     * 通知聊天列表群已读状态.
     */
    public const TypeNotifyMyGroupLastRead = 22;

    /**
     * 通知内用户身份变更.
     */
    public const TypeNotifyUserRoleChange = 23;

    /**
     * ***讓監聽端辨識何種通知的type欄位, 避免命名覆蓋, 使用底線作為代替的key名稱.***
     */
    public const MsgTypeField = '___';

    /**
     * 动作.
     *
     * @var int
     */
    public int $type;

    /**
     * 可选参数.
     *
     * @var array
     */
    public array $data = [];

    public static function make(int $type, array $data = []): static
    {
        $u       = new static();
        $u->type = $type;
        $u->data = $data;

        return $u;
    }

    public function toArray(): array
    {
        $arr = [static::MsgTypeField => $this->type];
        if ( ! empty($this->data)) {
            foreach ($this->data as $k => $v) {
                $arr[$k] = $v;
            }
        }

        return $arr;
    }

    public function jsonSerialize(): string
    {
        return json_encode($this->toArray());
    }

    /**
     * 取管道redis pool
     *
     * @param  string  $stream
     *
     * @return \Hyperf\Redis\RedisProxy
     */
    public static function getRedis(string $stream = self::NotifyStream): RedisProxy
    {
        return Redis::instance($stream);
    }

    /**
     * 將消息推至redis stream. !!注意$data是要送進redis stream的數據, 因此僅限一維陣列, 要多層記得先轉字串, 後面處理時再解回來
     *
     * @param  int     $type
     * @param  array   $data
     * @param  string  $stream
     *
     * @return bool
     */
    public static function push(int $type, array $data, string $stream = self::NotifyStream): bool
    {
        return (bool)static::getRedis($stream)->xadd($stream, '*', static::make($type, $data)->toArray());
    }

}

