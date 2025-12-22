<?php

namespace App\BusinessModules\Features\AIAssistant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemAnalysisSection extends Model
{
    protected $fillable = [
        'report_id',
        'section_type',
        'data',
        'analysis',
        'score',
        'status',
        'severity',
        'recommendations',
        'summary',
    ];

    protected $casts = [
        'data' => 'array',
        'recommendations' => 'array',
        'score' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Отчет, к которому относится секция
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(SystemAnalysisReport::class, 'report_id');
    }

    /**
     * Получить человекочитаемое название раздела
     */
    public function getSectionName(): string
    {
        return match($this->section_type) {
            'budget' => 'Бюджет и финансы',
            'schedule' => 'График работ',
            'materials' => 'Материалы',
            'workers' => 'Рабочие и бригады',
            'contracts' => 'Контракты',
            'risks' => 'Риски',
            'performance' => 'Эффективность (KPI)',
            'recommendations' => 'Рекомендации',
            default => 'Неизвестный раздел',
        };
    }

    /**
     * Получить иконку раздела
     */
    public function getSectionIcon(): string
    {
        return match($this->section_type) {
            'budget' => '💰',
            'schedule' => '📅',
            'materials' => '📦',
            'workers' => '👷',
            'contracts' => '📄',
            'risks' => '⚠️',
            'performance' => '📊',
            'recommendations' => '💡',
            default => '❓',
        };
    }

    /**
     * Проверка, критична ли проблема
     */
    public function isCritical(): bool
    {
        return $this->severity === 'critical' || $this->status === 'critical';
    }

    /**
     * Проверка, есть ли предупреждения
     */
    public function hasWarning(): bool
    {
        return $this->status === 'warning' || $this->severity === 'high';
    }

    /**
     * Получить цвет статуса
     */
    public function getStatusColor(): string
    {
        return match($this->status) {
            'good' => 'green',
            'warning' => 'orange',
            'critical' => 'red',
            default => 'gray',
        };
    }

    /**
     * Scope: по типу раздела
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('section_type', $type);
    }

    /**
     * Scope: критические разделы
     */
    public function scopeCritical($query)
    {
        return $query->where('status', 'critical')
            ->orWhere('severity', 'critical');
    }
}

