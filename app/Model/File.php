<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int    $id          ID
 * @property int    $type        档案类型
 * @property string $path        档案路径
 * @property string $md5         档案MD5
 * @property int    $create_time 创建时间
 */
class File extends Model
{

    /**
     * 档案类型 - 图片.
     */
    public const TypeImage = 1;

    /**
     * The table associated with the model.
     */
    protected ?string $table = 'file';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = ['id', 'type', 'path', 'md5', 'create_time'];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = ['id' => 'integer', 'type' => 'integer', 'create_time' => 'integer'];

}
