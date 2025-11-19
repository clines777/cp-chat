<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int    $id       ID
 * @property string $filename 档名
 */
class Avatar extends Model
{

    /**
     * 用户默认头像ID
     */
    public const UserDefaultAvatarId = 1;

    /**
     * 用户头像.
     */
    public const TypeUser = 0;

    /**
     * 管理者头像.
     */
    public const TypeAdmin = 1;

    /**
     * The table associated with the model.
     */
    protected ?string $table = 'avatar';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = ['id', 'filename'];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = ['id' => 'integer'];

}
