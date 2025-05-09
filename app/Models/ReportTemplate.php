<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportTemplate extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'report_templates';

    protected $fillable = [
        'name',
        'report_type', // e.g., 'material_usage', 'work_completion'
        'organization_id',
        'user_id', // Creator or null if system/default template
        'columns_config', // JSON: [{header: 'Header1', data_key: 'key1', order: 1, format_options: {}}, ...]
        'is_default',
    ];

    protected $casts = [
        'columns_config' => 'array',
        'is_default' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Организация, которой принадлежит шаблон (если не системный).
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    /**
     * Пользователь, создавший шаблон (если не системный).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
} 