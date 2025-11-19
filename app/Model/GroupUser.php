<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $id                   房间用户ID
 * @property int $user_id              用户ID, user.id
 * @property int $group_id             群ID, 对应group.id
 * @property int $is_ban               是否被禁言
 * @property int $join_time            入群时间
 * @property int $role_type            角色类型 0:一般用户 1:mod 2:群主
 * @property int $last_visible_chat_id 最后可见讯息ID
 * @property int $last_read_chat_id    最后已读讯息ID
 * @property int $pin_time             置顶时间, 不为0代表已置顶
 * @property int $update_time          更新时间
 */
class GroupUser extends Model
{

    /**
     * 已禁言.
     */
    public const BanYes = 1;

    /**
     * 未禁言.
     */
    public const BanNo = 0;

    /**
     * 一般用户.
     */
    public const RoleUser = 0;

    /**
     * 群Mod
     */
    public const RoleMod = 1;

    /**
     * 群主
     */
    public const RoleOwner = 2;

    /**
     * 特殊角色 - 用于标记已退群
     */
    public const RoleQuit = -1;

    /**
     * The table associated with the model.
     */
    protected ?string $table = 'group_user';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = ['id', 'user_id', 'group_id', 'is_ban', 'join_time', 'role_type', 'last_visible_chat_id', 'last_read_chat_id', 'pin_time', 'update_time'];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts
        = [
            'id'                   => 'integer',
            'user_id'              => 'integer',
            'group_id'             => 'integer',
            'is_ban'               => 'integer',
            'join_time'            => 'integer',
            'role_type'            => 'integer',
            'last_visible_chat_id' => 'integer',
            'last_read_chat_id'    => 'integer',
            'pin_time'             => 'integer',
            'update_time'          => 'integer',
        ];

}
