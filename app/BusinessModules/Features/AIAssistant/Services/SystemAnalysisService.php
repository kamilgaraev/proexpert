<?php

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Models\SystemAnalysisReport;
use App\BusinessModules\Features\AIAssistant\Models\SystemAnalysisSection;
use App\BusinessModules\Features\AIAssistant\Models\SystemAnalysisHistory;
use App\BusinessModules\Features\AIAssistant\Actions\Analysis\CollectBudgetDataAction;
use App\BusinessModules\Features\AIAssistant\Actions\Analysis\CollectScheduleDataAction;
use App\BusinessModules\Features\AIAssistant\Actions\Analysis\CollectMaterialsDataAction;
use App\BusinessModules\Features\AIAssistant\Actions\Analysis\CollectWorkersDataAction;
use App\BusinessModules\Features\AIAssistant\Actions\Analysis\CollectContractsDataAction;
use App\BusinessModules\Features\AIAssistant\Actions\Analysis\CalculateKPIAction;
use App\BusinessModules\Features\AIAssistant\Services\UsageTracker;
use App\BusinessModules\Features\AdvancedDashboard\Services\DashboardCacheService;
use App\Models\Project;
use App\Models\User;
use App\Services\Logging\LoggingService;
use Illuminate\Support\Facades\DB;

class SystemAnalysisService
{
    protected AIAnalyzerService $aiAnalyzer;
    protected UsageTracker $usageTracker;
    protected DashboardCacheService $cache;
    protected LoggingService $logging;

    public function __construct(
        AIAnalyzerService $aiAnalyzer,
        UsageTracker $usageTracker,
        DashboardCacheService $cache,
        LoggingService $logging
    ) {
        $this->aiAnalyzer = $aiAnalyzer;
        $this->usageTracker = $usageTracker;
        $this->cache = $cache;
        $this->logging = $logging;
    }

    /**
     * Анализ одного проекта
     */
    public function analyzeProject(int $projectId, int $organizationId, User $user, array $options = []): array
    {
        // Проверяем кеш
        $cacheKey = "system_analysis:project:{$projectId}";
        $cacheTags = ['system_analysis', "project:{$projectId}", "org:{$organizationId}"];
        
        $useCache = $options['use_cache'] ?? true;
        
        if ($useCache) {
            $cached = $this->cache->getCachedWidget($cacheKey, $cacheTags);
            if ($cached) {
                $this->logging->technical('system_analysis.cache_hit', ['project_id' => $projectId]);
                return $cached;
            }
        }

        // Создаем запись отчета
        $report = SystemAnalysisReport::create([
            'project_id' => $projectId,
            'organization_id' => $organizationId,
            'analysis_type' => 'single_project',
            'status' => 'processing',
            'created_by_user_id' => $user->id,
            'sections' => $options['sections'] ?? config('ai-assistant.system_analysis.sections', []),
        ]);

        try {
            // Собираем данные
            $collectedData = $this->collectProjectData($projectId, $organizationId);

            // Анализируем через AI
            $analyses = $this->performAIAnalysis($collectedData, $report->sections);

            // Рассчитываем общую оценку
            $overallScore = $this->calculateOverallScore($analyses);
            $overallStatus = $this->determineOverallStatus($overallScore);

            // Сохраняем результаты
            $report->update([
                'status' => 'completed',
                'results' => [
                    'data' => $collectedData,
                    'analyses' => $analyses,
                ],
                'overall_score' => $overallScore,
                'overall_status' => $overallStatus,
                'ai_model' => config('ai-assistant.llm.provider', 'deepseek'),
                'tokens_used' => $this->calculateTokensUsed($analyses),
                'cost' => $this->calculateCost($analyses),
                'completed_at' => now(),
            ]);

            // Сохраняем разделы
            $this->saveSections($report, $collectedData, $analyses);

            // Сохраняем историю (если есть предыдущий анализ)
            $this->saveHistory($report);

            // Формируем ответ
            $result = $this->formatAnalysisResult($report, $collectedData, $analyses);

            // Кешируем
            $ttl = config('ai-assistant.system_analysis.cache_ttl', 3600);
            $this->cache->cacheWidget($cacheKey, $result, $ttl, $cacheTags);

            $this->logging->business('system_analysis.completed', [
                'report_id' => $report->id,
                'project_id' => $projectId,
                'organization_id' => $organizationId,
                'overall_score' => $overallScore,
            ]);

            return $result;

        } catch (\Exception $e) {
            $report->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            $this->logging->technical('system_analysis.failed', [
                'report_id' => $report->id,
                'project_id' => $projectId,
                'error' => $e->getMessage(),
            ], 'error');

            throw $e;
        }
    }

    /**
     * Анализ всех проектов организации
     */
    public function analyzeOrganization(int $organizationId, User $user, array $options = []): array
    {
        $projects = Project::where('organization_id', $organizationId)
            ->where('is_archived', false)
            ->get();

        $projectAnalyses = [];
        
        foreach ($projects as $project) {
            try {
                $analysis = $this->analyzeProject($project->id, $organizationId, $user, $options);
                $projectAnalyses[] = $analysis;
            } catch (\Exception $e) {
                $this->logging->technical('system_analysis.project_failed', [
                    'project_id' => $project->id,
                    'error' => $e->getMessage(),
                ], 'error');
                
                // Продолжаем с другими проектами
                continue;
            }
        }

        // Агрегируем результаты
        $aggregated = $this->aggregateOrganizationResults($projectAnalyses);

        // Создаем общий отчет
        $report = SystemAnalysisReport::create([
            'organization_id' => $organizationId,
            'analysis_type' => 'all_projects',
            'status' => 'completed',
            'created_by_user_id' => $user->id,
            'results' => $aggregated,
            'overall_score' => $aggregated['overall_score'] ?? 0,
            'overall_status' => $aggregated['overall_status'] ?? 'warning',
            'completed_at' => now(),
        ]);

        return [
            'report_id' => $report->id,
            'analysis_type' => 'all_projects',
            'projects_analyzed' => count($projectAnalyses),
            'aggregated_results' => $aggregated,
        ];
    }

    /**
     * Получить отчет
     */
    public function getReport(int $reportId): array
    {
        $report = SystemAnalysisReport::with(['project', 'analysisSections'])
            ->findOrFail($reportId);

        return $this->formatReportForOutput($report);
    }

    /**
     * Пересчитать анализ
     */
    public function recalculate(int $reportId): array
    {
        $report = SystemAnalysisReport::findOrFail($reportId);
        
        if (!$report->project_id) {
            throw new \Exception('Невозможно пересчитать анализ всей организации');
        }

        // Инвалидируем кеш
        $this->invalidateCache($report->project_id, $report->organization_id);

        // Запускаем новый анализ
        $user = $report->createdBy;
        return $this->analyzeProject($report->project_id, $report->organization_id, $user, [
            'use_cache' => false,
            'sections' => $report->sections,
        ]);
    }

    /**
     * Сравнить два отчета
     */
    public function compareReports(int $reportId, int $previousReportId): array
    {
        $report = SystemAnalysisReport::with('analysisSections')->findOrFail($reportId);
        $previousReport = SystemAnalysisReport::with('analysisSections')->findOrFail($previousReportId);

        $comparison = [
            'current' => [
                'id' => $report->id,
                'date' => $report->created_at->format('Y-m-d H:i'),
                'overall_score' => $report->overall_score,
                'overall_status' => $report->overall_status,
            ],
            'previous' => [
                'id' => $previousReport->id,
                'date' => $previousReport->created_at->format('Y-m-d H:i'),
                'overall_score' => $previousReport->overall_score,
                'overall_status' => $previousReport->overall_status,
            ],
            'changes' => [],
        ];

        // Сравниваем общую оценку
        $scoreDiff = $report->overall_score - $previousReport->overall_score;
        $comparison['overall_change'] = [
            'score_difference' => $scoreDiff,
            'direction' => $scoreDiff > 0 ? 'improved' : ($scoreDiff < 0 ? 'declined' : 'stable'),
            'percentage' => $previousReport->overall_score > 0 
                ? round(($scoreDiff / $previousReport->overall_score) * 100, 2) 
                : 0,
        ];

        // Сравниваем разделы
        foreach ($report->analysisSections as $section) {
            $prevSection = $previousReport->analysisSections
                ->where('section_type', $section->section_type)
                ->first();

            if ($prevSection) {
                $sectionDiff = $section->score - $prevSection->score;
                $comparison['changes'][$section->section_type] = [
                    'current_score' => $section->score,
                    'previous_score' => $prevSection->score,
                    'difference' => $sectionDiff,
                    'direction' => $sectionDiff > 0 ? 'improved' : ($sectionDiff < 0 ? 'declined' : 'stable'),
                    'current_status' => $section->status,
                    'previous_status' => $prevSection->status,
                ];
            }
        }

        return $comparison;
    }

    /**
     * Собрать данные проекта
     */
    private function collectProjectData(int $projectId, int $organizationId): array
    {
        $budgetAction = new CollectBudgetDataAction();
        $scheduleAction = new CollectScheduleDataAction();
        $materialsAction = new CollectMaterialsDataAction();
        $workersAction = new CollectWorkersDataAction();
        $contractsAction = new CollectContractsDataAction();
        $kpiAction = new CalculateKPIAction();

        $data = [
            'budget' => $budgetAction->execute($projectId, $organizationId),
            'schedule' => $scheduleAction->execute($projectId, $organizationId),
            'materials' => $materialsAction->execute($projectId, $organizationId),
            'workers' => $workersAction->execute($projectId, $organizationId),
            'contracts' => $contractsAction->execute($projectId, $organizationId),
        ];

        // KPI рассчитывается на основе всех собранных данных
        $data['kpi'] = $kpiAction->execute($projectId, $organizationId, $data);

        return $data;
    }

    /**
     * Выполнить AI-анализ
     */
    private function performAIAnalysis(array $collectedData, array $enabledSections): array
    {
        $analyses = [];

        if ($enabledSections['budget'] ?? true) {
            $analyses['budget'] = $this->aiAnalyzer->analyzeBudget($collectedData['budget']);
        }

        if ($enabledSections['schedule'] ?? true) {
            $analyses['schedule'] = $this->aiAnalyzer->analyzeSchedule($collectedData['schedule']);
        }

        if ($enabledSections['materials'] ?? true) {
            $analyses['materials'] = $this->aiAnalyzer->analyzeMaterials($collectedData['materials']);
        }

        if ($enabledSections['workers'] ?? true) {
            $analyses['workers'] = $this->aiAnalyzer->analyzeWorkers($collectedData['workers']);
        }

        if ($enabledSections['contracts'] ?? true) {
            $analyses['contracts'] = $this->aiAnalyzer->analyzeContracts($collectedData['contracts']);
        }

        if ($enabledSections['risks'] ?? true) {
            $analyses['risks'] = $this->aiAnalyzer->analyzeRisks($collectedData);
        }

        if ($enabledSections['performance'] ?? true) {
            $analyses['performance'] = $this->aiAnalyzer->analyzePerformance($collectedData['kpi']);
        }

        if ($enabledSections['recommendations'] ?? true) {
            $analyses['recommendations'] = $this->aiAnalyzer->generateRecommendations($collectedData, $analyses);
        }

        return $analyses;
    }

    /**
     * Сохранить разделы анализа
     */
    private function saveSections(SystemAnalysisReport $report, array $collectedData, array $analyses): void
    {
        foreach ($analyses as $sectionType => $analysis) {
            SystemAnalysisSection::create([
                'report_id' => $report->id,
                'section_type' => $sectionType,
                'data' => $collectedData[$sectionType] ?? [],
                'analysis' => $analysis['analysis'] ?? '',
                'score' => $analysis['score'] ?? null,
                'status' => $analysis['status'] ?? null,
                'severity' => $this->determineSeverity($analysis['status'] ?? 'warning'),
                'recommendations' => $analysis['recommendations'] ?? [],
                'summary' => $analysis['summary'] ?? '',
            ]);
        }
    }

    /**
     * Сохранить историю
     */
    private function saveHistory(SystemAnalysisReport $report): void
    {
        if (!$report->project_id) {
            return;
        }

        // Найти предыдущий анализ этого проекта
        $previousReport = SystemAnalysisReport::where('project_id', $report->project_id)
            ->where('id', '!=', $report->id)
            ->where('status', 'completed')
            ->latest('completed_at')
            ->first();

        if ($previousReport) {
            $comparison = $this->compareReports($report->id, $previousReport->id);
            
            SystemAnalysisHistory::create([
                'report_id' => $report->id,
                'previous_report_id' => $previousReport->id,
                'changes' => $comparison['changes'],
                'comparison' => $comparison,
            ]);
        }
    }

    /**
     * Рассчитать общую оценку
     */
    private function calculateOverallScore(array $analyses): int
    {
        $scores = [];
        
        foreach ($analyses as $section => $analysis) {
            if (isset($analysis['score']) && is_numeric($analysis['score'])) {
                $scores[] = (int) $analysis['score'];
            }
        }

        return !empty($scores) ? (int) round(array_sum($scores) / count($scores)) : 0;
    }

    /**
     * Определить общий статус
     */
    private function determineOverallStatus(int $score): string
    {
        if ($score >= 70) {
            return 'good';
        } elseif ($score >= 50) {
            return 'warning';
        } else {
            return 'critical';
        }
    }

    /**
     * Определить уровень серьезности
     */
    private function determineSeverity(string $status): string
    {
        return match ($status) {
            'critical' => 'critical',
            'warning' => 'high',
            'good' => 'low',
            default => 'medium',
        };
    }

    /**
     * Рассчитать использованные токены
     */
    private function calculateTokensUsed(array $analyses): int
    {
        // Примерная оценка: каждый раздел ~3500 токенов
        return count($analyses) * 3500;
    }

    /**
     * Рассчитать стоимость
     */
    private function calculateCost(array $analyses): float
    {
        $tokens = $this->calculateTokensUsed($analyses);
        
        // Используем UsageTracker для расчета стоимости
        $model = config('ai-assistant.llm.provider', 'deepseek') . '-chat';
        
        return $this->usageTracker->calculateCost($tokens, $model);
    }

    /**
     * Форматировать результат анализа
     */
    private function formatAnalysisResult(SystemAnalysisReport $report, array $collectedData, array $analyses): array
    {
        $project = $report->project;

        return [
            'report_id' => $report->id,
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status,
            ],
            'analysis_type' => $report->analysis_type,
            'generated_at' => $report->completed_at->toISOString(),
            'overall_score' => $report->overall_score,
            'overall_status' => $report->overall_status,
            'tokens_used' => $report->tokens_used,
            'cost_rub' => $report->cost,
            'sections' => $this->formatSectionsForOutput($report->analysisSections, $collectedData, $analyses),
        ];
    }

    /**
     * Форматировать разделы для вывода
     */
    private function formatSectionsForOutput($sections, array $collectedData, array $analyses): array
    {
        $formatted = [];

        foreach ($sections as $section) {
            $formatted[$section->section_type] = [
                'score' => $section->score,
                'status' => $section->status,
                'summary' => $section->summary,
                'analysis' => $section->analysis,
                'data' => $section->data,
                'recommendations' => $section->recommendations,
            ];
        }

        return $formatted;
    }

    /**
     * Форматировать отчет для вывода
     */
    private function formatReportForOutput(SystemAnalysisReport $report): array
    {
        return [
            'report_id' => $report->id,
            'project' => $report->project ? [
                'id' => $report->project->id,
                'name' => $report->project->name,
                'status' => $report->project->status,
            ] : null,
            'organization_id' => $report->organization_id,
            'analysis_type' => $report->analysis_type,
            'status' => $report->status,
            'overall_score' => $report->overall_score,
            'overall_status' => $report->overall_status,
            'generated_at' => $report->completed_at?->toISOString(),
            'created_by' => [
                'id' => $report->createdBy->id,
                'name' => $report->createdBy->name,
            ],
            'sections' => $report->analysisSections->map(function ($section) {
                return [
                    'type' => $section->section_type,
                    'name' => $section->getSectionName(),
                    'icon' => $section->getSectionIcon(),
                    'score' => $section->score,
                    'status' => $section->status,
                    'summary' => $section->summary,
                    'analysis' => $section->analysis,
                    'recommendations' => $section->recommendations,
                ];
            }),
        ];
    }

    /**
     * Агрегировать результаты организации
     */
    private function aggregateOrganizationResults(array $projectAnalyses): array
    {
        if (empty($projectAnalyses)) {
            return [
                'overall_score' => 0,
                'overall_status' => 'warning',
                'projects_summary' => [],
            ];
        }

        $totalScore = 0;
        $projectsSummary = [];
        $sectionsAggregated = [];

        foreach ($projectAnalyses as $analysis) {
            $totalScore += $analysis['overall_score'];
            
            $projectsSummary[] = [
                'project_id' => $analysis['project']['id'],
                'project_name' => $analysis['project']['name'],
                'score' => $analysis['overall_score'],
                'status' => $analysis['overall_status'],
            ];

            // Агрегируем по разделам
            foreach ($analysis['sections'] as $sectionType => $sectionData) {
                if (!isset($sectionsAggregated[$sectionType])) {
                    $sectionsAggregated[$sectionType] = [
                        'scores' => [],
                        'critical_count' => 0,
                        'warning_count' => 0,
                    ];
                }
                
                $sectionsAggregated[$sectionType]['scores'][] = $sectionData['score'];
                
                if ($sectionData['status'] === 'critical') {
                    $sectionsAggregated[$sectionType]['critical_count']++;
                } elseif ($sectionData['status'] === 'warning') {
                    $sectionsAggregated[$sectionType]['warning_count']++;
                }
            }
        }

        $avgScore = (int) round($totalScore / count($projectAnalyses));

        return [
            'overall_score' => $avgScore,
            'overall_status' => $this->determineOverallStatus($avgScore),
            'projects_analyzed' => count($projectAnalyses),
            'projects_summary' => $projectsSummary,
            'sections_summary' => $this->formatSectionsSummary($sectionsAggregated),
        ];
    }

    /**
     * Форматировать сводку по разделам
     */
    private function formatSectionsSummary(array $sectionsAggregated): array
    {
        $summary = [];

        foreach ($sectionsAggregated as $sectionType => $data) {
            $avgScore = !empty($data['scores']) 
                ? (int) round(array_sum($data['scores']) / count($data['scores'])) 
                : 0;

            $summary[$sectionType] = [
                'avg_score' => $avgScore,
                'critical_projects' => $data['critical_count'],
                'warning_projects' => $data['warning_count'],
            ];
        }

        return $summary;
    }

    /**
     * Инвалидировать кеш
     */
    private function invalidateCache(int $projectId, int $organizationId): void
    {
        $cacheKey = "system_analysis:project:{$projectId}";
        $cacheTags = ['system_analysis', "project:{$projectId}", "org:{$organizationId}"];
        
        $this->cache->forget($cacheKey, $cacheTags);
    }
}

