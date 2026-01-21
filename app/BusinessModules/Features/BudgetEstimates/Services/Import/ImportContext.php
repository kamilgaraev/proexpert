<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\Models\Estimate;

class ImportContext
{
    public ?int $currentSectionId = null;
    public ?int $currentWorkId = null; // ID родительской работы (для ресурсов)
    
    // Кэш разделов: path -> id
    public array $sectionsMap = [];

    // Статистика
    public int $importedCount = 0;
    public int $skippedCount = 0;
    public int $sectionsCreatedCount = 0;
    public int $codeMatchesCount = 0;
    public int $nameMatchesCount = 0;
    
    // Статистика по типам
    public array $typeStats = [
        'work' => 0,
        'material' => 0,
        'equipment' => 0,
        'machinery' => 0,
        'labor' => 0,
        'summary' => 0,
    ];

    public function __construct(
        public int $organizationId,
        public Estimate $estimate,
        public array $settings,
        public array $matchingConfig,
        public ?string $jobId = null
    ) {}

    public function incrementStat(string $type): void
    {
        if (isset($this->typeStats[$type])) {
            $this->typeStats[$type]++;
        }
    }
}
