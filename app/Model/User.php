<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $id 房间用户ID
 * @property string $site_bid 站点标示
 * @property int $ext_member_id 外部member_id
 * @property string $ext_username 外部用户名
 * @property int $ext_platform_type 彩票端平台类型
 * @property int $user_level 用户等级
 * @property int $last_login_time 最后登入时间
 * @property int $create_time 创建时间
 * @property int $avatar_id 用户头像ID
 * @property int $update_time 次要资讯更新时间, eg, 等级
 * @property int $is_global_ban 是否全域禁言 0:否 1:是
 * @property string $code 用户聊天号
 * @property int $sys_chat_last_read_id 系统消息最后已读ID
 */
class User extends Model
{

    public const IsGlobalBanYes = 1;

    public const IsGlobalBanNo = 0;

    /**
     * The table associated with the model.
     */
    protected ?string $table = 'user';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable
        = ['id', 'site_bid', 'ext_member_id', 'ext_username', 'ext_platform_type', 'user_level', 'last_login_time', 'create_time', 'avatar_id', 'update_time', 'is_global_ban', 'code', 'sys_chat_last_read_id'];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts
        = ['id' => 'integer', 'ext_member_id' => 'integer', 'ext_platform_type' => 'integer', 'user_level' => 'integer', 'last_login_time' => 'integer', 'create_time' => 'integer', 'avatar_id' => 'integer', 'update_time' => 'integer', 'is_global_ban' => 'integer', 'sys_chat_last_read_id' => 'integer'];

}
