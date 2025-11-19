<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $id ID
 * @property int $group_id 群内红包, 所屬群組ID
 * @property string $site_bid 站点编号
 * @property int $user_id 群内红包, 送紅包的用戶ID
 * @property int $ext_member_id 彩票用户ID
 * @property string $ext_username 彩票用户名
 * @property int $admin_id 系统红包管理者ID
 * @property string $unit_amount 普通红包每包金额
 * @property int $count 群内红包, 红包个数
 * @property string $title 红包标语
 * @property int $game_company_id 游戏币的厂商ID
 * @property int $scene_type 系统类型 1: 群内红包 2:系统红包
 * @property int $create_type 群内红包, 创建类型 0:平台 1:一般用户
 * @property int $amount_type 红包分配类型 1:普通红包 2: 手气红包
 * @property int $bonus_type 奖励类型 1: 红包彩金 2:专属厂商游戏币
 * @property int $close_type 关闭类型 0:未关闭 1:超时关闭 2:手动关闭 3:领完关闭
 * @property string $total_amount 群内红包, 手气红包总金额
 * @property string $require_multi 打码倍数
 * @property int $start_time 开始时间
 * @property int $end_time 结束时间
 * @property int $create_time 创建时间
 * @property int $update_time 更新时间
 */
class LuckyMoney extends Model
{

    /**
     * 关闭状态 - 未关闭.
     */
    public const CloseTypeNone = 0;

    /**
     * 关闭状态 - 超时自动关闭.
     */
    public const CloseTypeExpire = 1;

    /**
     * 关闭状态 - 主动手动关闭.
     */
    public const CloseTypeManual = 2;

    /**
     * 关闭状态 - 领完自动关闭.
     */
    public const CloseTypeNoStock = 3;

    /**
     * 每包固定金额
     */
    public const AmountTypeFixed = 1;

    /**
     * 每包随机金额.
     */
    public const AmountTypeRand = 2;

    /**
     * 奖励类型 - 红包彩金
     */
    public const BonusTypeLuckyMoney = 1;

    /**
     * 奖励类型 - 指定厂商游戏币
     */
    public const BonusTypeGameCoin = 2;

    /**
     * 创建类型 - 管理员创建
     */
    public const CreateTypePlatform = 0;

    /**
     * 创建类型 - 一般用户创建.
     */
    public const CreateTypeUser = 1;

    /**
     * 红包场景类型 - 一般群内红包.
     */
    public const SceneTypeGroup = 1;

    /**
     * 红包场景类型 - 系统群红包
     */
    public const SceneTypeSys = 2;

    /**
     * 系统红包固定游戏厂商
     */
    public const SysLmGameCompany = 26;

    /**
     * The table associated with the model.
     */
    protected ?string $table = 'lucky_money';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable
        = ['id', 'group_id', 'site_bid', 'user_id', 'ext_member_id', 'ext_username', 'admin_id', 'unit_amount', 'count', 'title', 'game_company_id', 'scene_type', 'create_type', 'amount_type', 'bonus_type', 'close_type', 'total_amount', 'require_multi', 'start_time', 'end_time', 'create_time', 'update_time'];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts
        = ['id' => 'integer', 'group_id' => 'integer', 'user_id' => 'integer', 'ext_member_id' => 'integer', 'admin_id' => 'integer', 'count' => 'integer', 'game_company_id' => 'integer', 'scene_type' => 'integer', 'create_type' => 'integer', 'amount_type' => 'integer', 'bonus_type' => 'integer', 'close_type' => 'integer', 'start_time' => 'integer', 'end_time' => 'integer', 'create_time' => 'integer', 'update_time' => 'integer'];

}
