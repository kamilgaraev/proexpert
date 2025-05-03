<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class File extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'fileable_id',
        'fileable_type',
        'user_id',
        'name',
        'original_name',
        'path',
        'mime_type',
        'size',
        'disk',
        'type',
        'category',
        'additional_info',
    ];

    protected $casts = [
        'size' => 'integer',
        'additional_info' => 'array',
    ];

    /**
     * Получить организацию, которой принадлежит файл.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Получить пользователя, загрузившего файл.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Получить связанную модель.
     */
    public function fileable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Получить полный URL к файлу.
     */
    public function getUrlAttribute(): string
    {
        return \Storage::disk($this->disk)->url($this->path);
    }
}
