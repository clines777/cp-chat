<?php

declare(strict_types=1);

namespace App\Model;



/**
 * @property int $id ID
 * @property string $key 键值
 * @property string $value 值
 */
class Config extends Model
{
    /**
     * The table associated with the model.
     */
    protected ?string $table = 'config';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = ['id', 'key', 'value'];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = ['id' => 'integer'];
}
