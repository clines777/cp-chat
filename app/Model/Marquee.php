<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $id ID
 * @property int $type 類型 0:一般站內用戶 1:服務關閉前全域通告
 * @property string $content 内容
 * @property int $start_time 开始时间
 * @property int $end_time 结束时间
 * @property string $site_bid 站點編號
 * @property int $create_time  创建时间
 * @property int $update_time 更新时间
 */
class Marquee extends Model
{

    /**
     * 維護通知
     */
    public const TypeCloseServer = 0;

    /**
     * 一般通知
     */
    public const TypeNormal = 1;

    public const TypeDefault = self::TypeNormal;

    /**
     * The table associated with the model.
     */
    protected ?string $table = 'marquee';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = ['id', 'type', 'content', 'start_time', 'end_time', 'site_bid', 'create_time', 'update_time'];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = ['id' => 'integer', 'type' => 'integer', 'start_time' => 'integer', 'end_time' => 'integer', 'create_time' => 'integer', 'update_time' => 'integer'];

}
