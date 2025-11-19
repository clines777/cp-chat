<?php

declare(strict_types=1);

namespace App\Model;



/**
 * @property int $id ID
 * @property int $type 操作类型
 * @property string $site_bid 站点编号
 * @property int $create_time 创建时间
 * @property int $operator_id 操作者ID
 * @property int $operatee_id 被操作者用户ID
 * @property string $operator_name 操作人用户名
 * @property string $operatee_name 被操作者用户名
 * @property string $remark 操作备注
 */
class OperateLog extends Model
{
    public const BanUser = 1;

    public const UnBanUser = 2;

    public const KickUser = 3;

    /**
     * The table associated with the model.
     */
    protected ?string $table = 'operate_log';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = ['id', 'type', 'site_bid', 'create_time', 'operator_id', 'operatee_id', 'operator_name', 'operatee_name', 'remark'];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = ['id' => 'integer', 'type' => 'integer', 'create_time' => 'integer', 'operator_id' => 'integer', 'operatee_id' => 'integer'];
}
