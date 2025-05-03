<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use HasFactory;

    /**
     * Атрибуты, которые можно массово назначать.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'group',
    ];

    /**
     * Получить роли, связанные с этим разрешением.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    /**
     * Получить все разрешения, сгруппированные по группам.
     *
     * @return \Illuminate\Support\Collection
     */
    public static function getAllGrouped()
    {
        return self::all()->groupBy('group');
    }
}
