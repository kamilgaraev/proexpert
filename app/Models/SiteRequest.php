<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Enums\SiteRequest\SiteRequestStatusEnum;
use App\Enums\SiteRequest\SiteRequestPriorityEnum;
use App\Enums\SiteRequest\SiteRequestTypeEnum;
// Carbon не используется напрямую, можно убрать, если $casts['required_date'] => 'date' достаточно

class SiteRequest extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'user_id',
        'title',
        'description',
        'status',
        'priority',
        'request_type',
        'required_date',
        'notes',
    ];

    protected $casts = [
        'status' => SiteRequestStatusEnum::class,
        'priority' => SiteRequestPriorityEnum::class,
        'request_type' => SiteRequestTypeEnum::class,
        'required_date' => 'date',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Пользователь (прораб), создавший заявку.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Файлы (фотографии), прикрепленные к заявке.
     */
    public function files(): MorphMany
    {
        // Предполагаем, что модель File существует и настроена для полиморфных связей
        // с колонками fileable_id, fileable_type
        return $this->morphMany(File::class, 'fileable');
    }
} 