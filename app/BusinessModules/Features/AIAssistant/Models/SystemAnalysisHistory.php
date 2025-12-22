<?php

namespace App\BusinessModules\Features\AIAssistant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemAnalysisHistory extends Model
{
    protected $table = 'system_analysis_history';

    protected $fillable = [
        'report_id',
        'previous_report_id',
        'changes',
        'comparison',
    ];

    protected $casts = [
        'changes' => 'array',
        'comparison' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Текущий отчет
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(SystemAnalysisReport::class, 'report_id');
    }

    /**
     * Предыдущий отчет для сравнения
     */
    public function previousReport(): BelongsTo
    {
        return $this->belongsTo(SystemAnalysisReport::class, 'previous_report_id');
    }

    /**
     * Получить изменение по ключу
     */
    public function getChange(string $key)
    {
        return $this->changes[$key] ?? null;
    }

    /**
     * Получить сравнение по разделу
     */
    public function getSectionComparison(string $sectionType): ?array
    {
        return $this->comparison['sections'][$sectionType] ?? null;
    }

    /**
     * Проверить, улучшился ли показатель
     */
    public function hasImproved(string $metric): bool
    {
        $change = $this->getChange($metric);
        if (!$change || !isset($change['direction'])) {
            return false;
        }
        
        return $change['direction'] === 'improved';
    }

    /**
     * Проверить, ухудшился ли показатель
     */
    public function hasDeclined(string $metric): bool
    {
        $change = $this->getChange($metric);
        if (!$change || !isset($change['direction'])) {
            return false;
        }
        
        return $change['direction'] === 'declined';
    }

    /**
     * Получить процент изменения
     */
    public function getChangePercentage(string $metric): ?float
    {
        $change = $this->getChange($metric);
        return $change['percentage'] ?? null;
    }
}

