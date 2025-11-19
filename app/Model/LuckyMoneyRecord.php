<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $id ID
 * @property int $lm_id 紅包ID
 * @property int $user_id 用戶ID
 * @property int $group_id 群内红包, 群ID
 * @property string $site_bid 站点编号
 * @property int $ext_member_id 外部用户ID
 * @property string $ext_username 外部用户名
 * @property int $ext_platform_type 彩票平台类型
 * @property int $pack_id 该红包的拆包ID,lucky_money_pack.id
 * @property int $send_state 派奖状态 0:待派奖 1:已送往彩票端派奖中 2:已派奖 3:无法派奖
 * @property int $device 装置 1:PC 2:Wap 3:Android 4:iOS
 * @property int $tpl 外部版型 1:彩票 2:棋牌 5:体育
 * @property string $amount 金額
 * @property string $require_amount 要求打码量
 * @property int $create_time 创建时间
 * @property int $fund_time 实际派奖时间
 */
class LuckyMoneyRecord extends Model
{

    /**
     * 待派奖.
     */
    public const SentStateInit = 0;

    /**
     * 委托派奖中
     */
    public const SendStateSending = 1;

    /**
     * 已派奖.
     */
    public const SendStateSent = 2;

    /**
     * The table associated with the model.
     */
    protected ?string $table = 'lucky_money_record';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = ['id', 'lm_id', 'user_id', 'group_id', 'site_bid', 'ext_member_id', 'ext_username', 'ext_platform_type', 'pack_id', 'send_state', 'device', 'tpl', 'amount', 'require_amount', 'create_time', 'fund_time'];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = ['id' => 'integer', 'lm_id' => 'integer', 'user_id' => 'integer', 'group_id' => 'integer', 'ext_member_id' => 'integer', 'ext_platform_type' => 'integer', 'pack_id' => 'integer', 'send_state' => 'integer', 'device' => 'integer', 'tpl' => 'integer', 'create_time' => 'integer', 'fund_time' => 'integer'];

}
