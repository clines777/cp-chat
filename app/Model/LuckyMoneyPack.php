<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $id ID
 * @property int $lm_id 紅包ID
 * @property int $no 紅包序號
 * @property string $amount 金額
 * @property int $user_id 领取用户ID
 * @property int $is_taken 是否已被领 0:否 1:是
 * @property int $create_time 創建時間
 * @property int $update_time 领取时间
 */
class LuckyMoneyPack extends Model
{

    /**
     * 初始
     */
    public const IsTakenNo = 0;

    /**
     * 已领取
     */
    public const IsTakenYes = 1;

    /**
     * The table associated with the model.
     */
    protected ?string $table = 'lucky_money_pack';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = ['id', 'lm_id', 'no', 'amount', 'user_id', 'is_taken', 'create_time', 'update_time'];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = ['id' => 'integer', 'lm_id' => 'integer', 'no' => 'integer', 'user_id' => 'integer', 'is_taken' => 'integer', 'create_time' => 'integer', 'update_time' => 'integer'];

}
