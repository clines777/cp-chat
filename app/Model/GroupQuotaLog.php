<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int    $id            ID
 * @property string $site_bid      站点编号
 * @property int    $group_id      群ID
 * @property int    $alter_type    变更类型 0: 减少 1:增加
 * @property int    $quota_type    额度类型 1:平台奖金 2:游戏专属
 * @property string $ext_username  彩票用户名
 * @property int    $admin_id      操作者用户ID or 管理者ID
 * @property int    $operator_type 操作者类型 0:系统 1:一般用户 2:后台管理者
 * @property string $amount        变更额度
 * @property int    $create_time   变更时间
 */
class GroupQuotaLog extends Model
{

    /**
     * 操作角色 - 系统自动
     */
    public const OperateTypeRoleSys = 0;

    /**
     * 操作角色 - 群管理
     */
    public const OperateTypeRoleAdmin = 1;

    /**
     * 群管理.
     */
    public const OperateTypeGroupAdmin = 3;

    /**
     * 操作行为 - 后台扣除额度
     */
    public const ActionTypeAdminAdd = 1;

    /**
     * 操作行为 - 后台添加额度
     */
    public const ActionTypeAdminSub = 2;

    /**
     * 操作行为 - 发红包扣除额度
     */
    public const ActionTypeSendCost = 3;

    /**
     * 操作行为 - 红包余额返还.
     */
    public const ActionTypeReturnBalance = 4;

    /**
     * 额度类型 - 平台彩金.
     */
    public const QuotaTypeLuckyMoney = 1;

    /**
     * 额度类型 - 游戏彩金.
     */
    public const QuotaTypeGameCoin = 2;

    /**
     * 变动类型 - 减少
     */
    public const AlterTypeSub = 0;

    /**
     * 变动类型 - 增加
     */
    public const AlterTypeAdd = 1;

    /**
     * The table associated with the model.
     */
    protected ?string $table = 'group_quota_log';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = ['id', 'site_bid', 'group_id', 'alter_type', 'quota_type', 'ext_username', 'admin_id', 'operator_type', 'amount', 'create_time'];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts
        = [
            'id'            => 'integer',
            'group_id'      => 'integer',
            'alter_type'    => 'integer',
            'quota_type'    => 'integer',
            'admin_id'      => 'integer',
            'operator_type' => 'integer',
            'create_time'   => 'integer',
        ];

}
