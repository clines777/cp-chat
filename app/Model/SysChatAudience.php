<?php

declare(strict_types=1);

namespace App\Model;



/**
 * @property int $id ID
 * @property int $user_id 用户ID
 * @property int $ext_member_id 彩票用户ID
 * @property string $ext_username 彩票用户名
 * @property int $chat_id 消息ID
 * @property int $create_time 创建时间
 */
class SysChatAudience extends Model
{
    /**
     * The table associated with the model.
     */
    protected ?string $table = 'sys_chat_audience';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = ['id', 'user_id', 'ext_member_id', 'ext_username', 'chat_id', 'create_time'];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = ['id' => 'integer', 'user_id' => 'integer', 'ext_member_id' => 'integer', 'chat_id' => 'integer', 'create_time' => 'integer'];
}
