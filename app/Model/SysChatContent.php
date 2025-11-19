<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $id ID
 * @property int $audience_type 对象类型  0: 站点全用户 1: 指定单复数用户
 * @property int $deleted 是否删除
 * @property string $site_bid 站点编号
 * @property int $admin_id 彩票后台管理员ID
 * @property int $content_type 讯息类型 0: 一般 1:红包
 * @property int $create_time 创建时间
 * @property int $update_time 更新时间, 主要用于纪录删除时间
 * @property string $content 发言内容
 * @property string $extra 其他附属资讯json eg.红包ID跟有效期限
 * @property int $custom_id 自订ID(红包ID)
 */
class SysChatContent extends Model
{

    /**
     * 软删除 - 否.
     */
    public const DeleteNot = 0;

    /**
     * 软删除 - 是.
     */
    public const DeleteYes = 1;

    /**
     * 内容类型 - 一般讯息.
     */
    public const ContentTypeNormal = 0;

    /**
     * 内容类型 - 红包.
     */
    public const ContentTypeLuckyMoney = 1;

    /**
     * 消息对象 - 指定用户.
     */
    public const AudienceByUsers = 1;

    /**
     * 消息对象 - 站台全部.
     */
    public const AudienceBySite = 0;

    /**
     * The table associated with the model.
     */
    protected ?string $table = 'sys_chat_content';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable
        = ['id', 'audience_type', 'deleted', 'site_bid', 'admin_id', 'content_type', 'create_time', 'update_time', 'content', 'extra', 'custom_id'];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts
        = ['id' => 'integer', 'audience_type' => 'integer', 'deleted' => 'integer', 'admin_id' => 'integer', 'content_type' => 'integer', 'create_time' => 'integer', 'update_time' => 'integer', 'custom_id' => 'integer'];

}
