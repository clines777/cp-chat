<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $id ID
 * @property string $site_bid 站点编号
 * @property int $type 日志类型
 * @property int $ext_member_id 被操作彩票用户ID
 * @property string $ext_username 被操作彩票用户名
 * @property int $user_id 被操作用户ID
 * @property int $create_time 创建时间
 * @property int $scene 操作场景 0:此笔纪录不适用 1:群内 2:彩票后台
 * @property int $admin_id 彩票后台管理者ID
 * @property int $operator_type 操作人类型 0:用户自己 1:他人
 * @property int $operator_ext_member_id 群内操作人彩票用户ID
 * @property string $operator_username 群内操作人彩票用户名
 * @property int $operator_user_id 群内操作人用户ID
 * @property string $remark 备注
 */
class UserLog extends Model
{

    /**
     * The table associated with the model.
     */
    protected ?string $table = 'user_log';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable
        = ['id', 'site_bid', 'type', 'ext_member_id', 'ext_username', 'user_id', 'create_time', 'scene', 'admin_id', 'operator_type', 'operator_ext_member_id', 'operator_username', 'operator_user_id', 'remark'];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts
        = ['id' => 'integer', 'type' => 'integer', 'ext_member_id' => 'integer', 'user_id' => 'integer', 'create_time' => 'integer', 'scene' => 'integer', 'admin_id' => 'integer', 'operator_type' => 'integer', 'operator_ext_member_id' => 'integer', 'operator_user_id' => 'integer'];

}
