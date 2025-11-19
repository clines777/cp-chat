<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $id ID
 * @property string $site_bid 站点编号
 * @property int $is_dismiss 已解散 0:否 1:是
 * @property int $sort 大厅房间排序
 * @property int $visible 是否显示在大厅 0:否 1:是
 * @property string $code 群代号
 * @property int $user_limit 房间用户上限数, 管理者不算数
 * @property string $bulletin 群公告
 * @property int $open_join 开放主动加群
 * @property int $allow_url 是否允许群内一般成员送出链接网址 0:否 1:是
 * @property int $pin_chat_id 置顶发言ID
 * @property int $speak_user_level 发言等级限制
 * @property int $join_user_level 加入用户等级限制
 * @property int $owner_ext_member_id 房主的群聊user.id
 * @property string $owner_ext_username 群主用户名
 * @property int $owner_user_id 群主user.id
 * @property string $title 房名
 * @property string $remark 房间备注
 * @property string $lucky_money_quota 群组红包额度
 * @property string $game_coin_quota 游戏币额度
 * @property int $create_time 建立时间
 * @property int $update_time 更新时间
 * @property string $lobby_cover_pic_url 大厅群封面图
 * @property string $my_group_cover_pic_url 聊天列表封面图
 * @property string $ext_lobby_cover_pic_url 彩票端CDN大厅封面路径
 * @property string $ext_my_group_cover_pic_url 彩票端CDN聊天列表封面路径
 */
class Group extends Model
{

    public const VisibleYes = 1;

    public const VisibleNo = 0;

    public const OpenJoinYes = 1;

    public const OpenJoinNo = 0;

    public const TitleMaxLen = 30;

    public const BulletinMaxLen = 500;

    /**
     * 群组人数上限.
     */
    public const MaxUserLimit = 500;

    /**
     * 已解散 - 是.
     */
    public const DismissYes = 1;

    /**
     * 已解散 - 否.
     */
    public const DismissNo = 0;

    /**
     * The table associated with the model.
     */
    protected ?string $table = 'group';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable
        = ['id', 'site_bid', 'is_dismiss', 'sort', 'visible', 'code', 'user_limit', 'bulletin', 'open_join', 'allow_url', 'pin_chat_id', 'speak_user_level', 'join_user_level', 'owner_ext_member_id', 'owner_ext_username', 'owner_user_id', 'title', 'remark', 'lucky_money_quota', 'game_coin_quota', 'create_time', 'update_time', 'lobby_cover_pic_url', 'my_group_cover_pic_url', 'ext_lobby_cover_pic_url', 'ext_my_group_cover_pic_url'];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts
        = ['id' => 'integer', 'is_dismiss' => 'integer', 'sort' => 'integer', 'visible' => 'integer', 'user_limit' => 'integer', 'open_join' => 'integer', 'allow_url' => 'integer', 'pin_chat_id' => 'integer', 'speak_user_level' => 'integer', 'join_user_level' => 'integer', 'owner_ext_member_id' => 'integer', 'owner_user_id' => 'integer', 'create_time' => 'integer', 'update_time' => 'integer'];

}
