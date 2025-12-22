<?php

namespace App\BusinessModules\Features\AIAssistant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\Project;
use App\Models\Organization;
use App\Models\User;

class SystemAnalysisReport extends Model
{
    protected $fillable = [
        'project_id',
        'organization_id',
        'analysis_type',
        'status',
        'ai_model',
        'tokens_used',
        'cost',
        'sections',
        'results',
        'overall_score',
        'overall_status',
        'created_by_user_id',
        'completed_at',
        'error_message',
    ];

    protected $casts = [
        'sections' => 'array',
        'results' => 'array',
        'overall_score' => 'integer',
        'tokens_used' => 'integer',
        'cost' => 'decimal:4',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Проект, для которого выполнен анализ
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Организация
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Пользователь, создавший анализ
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Разделы анализа
     */
    public function analysisSections(): HasMany
    {
        return $this->hasMany(SystemAnalysisSection::class, 'report_id');
    }

    /**
     * История изменений
     */
    public function history(): HasOne
    {
        return $this->hasOne(SystemAnalysisHistory::class, 'report_id');
    }

    /**
     * Проверка, завершен ли анализ
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Проверка, провален ли анализ
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Проверка, в процессе ли анализ
     */
    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    /**
     * Получить раздел по типу
     */
    public function getSection(string $sectionType): ?SystemAnalysisSection
    {
        return $this->analysisSections()->where('section_type', $sectionType)->first();
    }

    /**
     * Scope: только завершенные анализы
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope: по организации
     */
    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope: по проекту
     */
    public function scopeForProject($query, int $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    /**
     * Scope: последние анализы
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}

