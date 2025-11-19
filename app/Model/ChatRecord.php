<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $id ID
 * @property string $site_bid 站点编号, 目前仅供查询备用
 * @property int $deleted 是否删除
 * @property int $group_id 群ID
 * @property int $user_id 用户ID
 * @property int $type 讯息类型 0: 一般 1:红包
 * @property int $create_time 创建时间
 * @property int $update_time 更新时间, 主要用于纪录删除时间
 * @property string $content 发言内容
 * @property string $bad_words 敏感词
 * @property string $extra 其他附属资讯json eg.红包ID跟有效期限
 * @property int $custom_id 特殊ID, 用来查询特殊讯息是哪一笔, 目前用于查找特定红包的对应聊天讯息
 */
class ChatRecord extends Model
{

    /**
     * 红包讯息状态 - 正常.
     */
    public const LmChatStateNormal = 1;

    /**
     * 红包讯息状态 - 已过期.
     */
    public const LmChatStateExpired = 2;

    /**
     * 一般消息
     */
    public const TypeNormal = 0;

    /**
     * 红包消息.
     */
    public const TypeLmMsg = 1;

    /**
     * 已软删 - 是
     */
    public const DeletedYes = 1;

    /**
     * The table associated with the model.
     */
    protected ?string $table = 'chat_record';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = ['id', 'site_bid', 'deleted', 'group_id', 'user_id', 'type', 'create_time', 'update_time', 'content', 'bad_words', 'extra', 'custom_id'];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = ['id' => 'integer', 'deleted' => 'integer', 'group_id' => 'integer', 'user_id' => 'integer', 'type' => 'integer', 'create_time' => 'integer', 'update_time' => 'integer', 'custom_id' => 'integer'];

}
