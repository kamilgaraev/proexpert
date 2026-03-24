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
use Illuminate\Support\Facades\Cache;
use App\Models\Project;
use App\Models\User;
use App\Services\Logging\LoggingService;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SystemAnalysisService
{
    private const AVAILABLE_SECTIONS = [
        'budget',
        'schedule',
        'materials',
        'workers',
        'contracts',
        'risks',
        'performance',
        'recommendations',
    ];

    protected AIAnalyzerService $aiAnalyzer;
    protected UsageTracker $usageTracker;
    protected int $cacheTtl;
    protected LoggingService $logging;

    public function __construct(
        AIAnalyzerService $aiAnalyzer,
        UsageTracker $usageTracker,
        LoggingService $logging,
        int $cacheTtl = 3600
    ) {
        $this->aiAnalyzer = $aiAnalyzer;
        $this->usageTracker = $usageTracker;
        $this->logging = $logging;
        $this->cacheTtl = $cacheTtl;
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
            $cached = $this->getCachedValue($cacheKey, $cacheTags);
            if ($cached) {
                $this->logging->technical('system_analysis.cache_hit', ['project_id' => $projectId]);
                return $cached;
            }
        }

        // Создаем запись отчета
        $sections = $this->normalizeSectionsSelection(
            $options['sections'] ?? config('ai-assistant.system_analysis.sections', [])
        );

        $report = $this->createReportRecord([
            'project_id' => $projectId,
            'organization_id' => $organizationId,
            'analysis_type' => 'single_project',
            'status' => 'processing',
            'created_by_user_id' => $user->id,
            'sections' => $sections,
        ]);

        try {
            // Собираем данные
            $collectedData = $this->collectProjectData($projectId, $organizationId);

            // Анализируем через AI
            $analyses = $this->performAIAnalysis($collectedData, $sections);
            $analyses = $this->postProcessAnalyses($collectedData, $analyses);

            // Рассчитываем общую оценку
            $overallScore = $this->calculateOverallScore($analyses);
            $overallStatus = $this->determineOverallStatus($overallScore);

            // Сохраняем результаты
            $this->updateReportRecord($report, [
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
            $ttl = config('ai-assistant.system_analysis.cache_ttl', $this->cacheTtl);
            $this->putCachedValue($cacheKey, $cacheTags, $result, $ttl);

            $this->logging->business('system_analysis.completed', [
                'report_id' => $report?->id,
                'project_id' => $projectId,
                'organization_id' => $organizationId,
                'overall_score' => $overallScore,
            ]);

            return $result;

        } catch (Throwable $e) {
            $this->updateReportRecord($report, [
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            $this->logging->technical('system_analysis.failed', [
                'report_id' => $report?->id,
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
        $projects = Project::accessibleByOrganization($organizationId)
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

    private function normalizeSectionsSelection(array $sections): array
    {
        if ($sections === []) {
            return array_fill_keys(self::AVAILABLE_SECTIONS, true);
        }

        if ($sections === [] || array_values($sections) !== $sections) {
            $normalized = [];

            foreach (self::AVAILABLE_SECTIONS as $section) {
                $normalized[$section] = (bool) ($sections[$section] ?? true);
            }

            return $normalized;
        }

        $selected = array_fill_keys($sections, true);
        $normalized = [];

        foreach (self::AVAILABLE_SECTIONS as $section) {
            $normalized[$section] = isset($selected[$section]);
        }

        return $normalized;
    }

    private function postProcessAnalyses(array $collectedData, array $analyses): array
    {
        foreach ($analyses as $sectionType => $analysis) {
            $heuristics = $this->buildHeuristicSectionAnalysis($sectionType, $collectedData, $analyses);

            $analyses[$sectionType] = [
                ...$heuristics,
                ...array_filter($analysis, static fn ($value) => $value !== null && $value !== ''),
            ];

            $analyses[$sectionType]['score'] = $this->normalizeScore($analyses[$sectionType]['score'] ?? $heuristics['score']);
            $analyses[$sectionType]['status'] = $this->normalizeStatus(
                $analyses[$sectionType]['status'] ?? $this->determineOverallStatus($analyses[$sectionType]['score'])
            );

            if (empty($analyses[$sectionType]['summary'])) {
                $analyses[$sectionType]['summary'] = $heuristics['summary'];
            }

            if (empty($analyses[$sectionType]['analysis'])) {
                $analyses[$sectionType]['analysis'] = $heuristics['analysis'];
            }

            if (empty($analyses[$sectionType]['recommendations'])) {
                $analyses[$sectionType]['recommendations'] = $heuristics['recommendations'];
            }
        }

        return $analyses;
    }

    private function buildHeuristicSectionAnalysis(string $sectionType, array $collectedData, array $analyses): array
    {
        return match ($sectionType) {
            'budget' => $this->buildBudgetHeuristics($collectedData['budget'] ?? []),
            'schedule' => $this->buildScheduleHeuristics($collectedData['schedule'] ?? []),
            'materials' => $this->buildMaterialsHeuristics($collectedData['materials'] ?? []),
            'workers' => $this->buildWorkersHeuristics($collectedData['workers'] ?? []),
            'contracts' => $this->buildContractsHeuristics($collectedData['contracts'] ?? []),
            'risks' => $this->buildRisksHeuristics($collectedData, $analyses),
            'performance' => $this->buildPerformanceHeuristics($collectedData['kpi'] ?? []),
            'recommendations' => $this->buildRecommendationsHeuristics($collectedData, $analyses),
            default => [
                'score' => 50,
                'status' => 'warning',
                'summary' => 'Раздел требует дополнительной оценки.',
                'analysis' => 'Недостаточно структурированных данных для надежной интерпретации.',
                'recommendations' => [],
            ],
        };
    }

    private function buildBudgetHeuristics(array $data): array
    {
        $spent = (float) ($data['percentage_spent'] ?? 0);
        $completion = (float) ($data['completion_percentage'] ?? 0);
        $gap = round($spent - $completion, 1);
        $score = 100 - max(0, $gap * 1.5);

        if ($spent > 100) {
            $score -= 20;
        }

        $score = $this->normalizeScore($score);
        $status = $this->determineOverallStatus($score);

        return [
            'score' => $score,
            'status' => $status,
            'summary' => $gap > 10
                ? "Расходы опережают фактическое выполнение работ на {$gap}%."
                : 'Расход бюджета близок к текущему прогрессу работ.',
            'analysis' => sprintf(
                'Освоение бюджета составляет %.1f%% при выполнении работ %.1f%%. Остаток бюджета: %.2f руб.',
                $spent,
                $completion,
                (float) ($data['remaining_budget'] ?? 0)
            ),
            'recommendations' => [
                [
                    'priority' => $gap > 10 ? 'high' : 'medium',
                    'action' => 'Проверить статьи затрат с максимальным отклонением.',
                    'impact' => 'Снижение риска перерасхода и раннее выявление неэффективных расходов.',
                ],
                [
                    'priority' => $spent > 100 ? 'high' : 'low',
                    'action' => 'Актуализировать финансовый прогноз до завершения проекта.',
                    'impact' => 'Понятный прогноз по потребности в финансировании до конца проекта.',
                ],
            ],
        ];
    }

    private function buildScheduleHeuristics(array $data): array
    {
        $overdue = (int) ($data['tasks_summary']['overdue'] ?? 0);
        $completion = (float) ($data['tasks_summary']['completion_percentage'] ?? 0);
        $score = $this->normalizeScore(100 - ($overdue * 8) - max(0, 50 - $completion));
        $status = $this->determineOverallStatus($score);

        return [
            'score' => $score,
            'status' => $status,
            'summary' => $overdue > 0
                ? "В графике есть {$overdue} просроченных задач."
                : 'Критических просрочек по графику не обнаружено.',
            'analysis' => sprintf(
                'Всего задач: %d, завершено: %d, просрочено: %d. Процент выполнения графика: %.1f%%.',
                (int) ($data['tasks_summary']['total'] ?? 0),
                (int) ($data['tasks_summary']['completed'] ?? 0),
                $overdue,
                $completion
            ),
            'recommendations' => [
                [
                    'priority' => $overdue > 0 ? 'high' : 'medium',
                    'action' => 'Пересобрать план по просроченным и критическим задачам.',
                    'impact' => 'Снижение риска каскадных сдвигов сроков.',
                ],
            ],
        ];
    }

    private function buildMaterialsHeuristics(array $data): array
    {
        $deficitCount = (int) ($data['deficit_analysis']['deficit_count'] ?? 0);
        $daysOfSupply = (int) ($data['days_of_supply'] ?? 0);
        $score = $this->normalizeScore(100 - ($deficitCount * 6) - ($daysOfSupply < 14 ? 30 : ($daysOfSupply < 30 ? 15 : 0)));
        $status = $this->determineOverallStatus($score);

        return [
            'score' => $score,
            'status' => $status,
            'summary' => $deficitCount > 0
                ? "Обнаружен дефицит по {$deficitCount} позициям материалов."
                : 'Материальные потребности проекта закрыты без критичного дефицита.',
            'analysis' => sprintf(
                'Запаса материалов ориентировочно хватит на %d дн. Оценочная стоимость закрытия дефицита: %.2f руб.',
                $daysOfSupply,
                (float) ($data['total_purchase_cost'] ?? 0)
            ),
            'recommendations' => [
                [
                    'priority' => $deficitCount > 0 ? 'high' : 'low',
                    'action' => 'Сформировать приоритетную закупку дефицитных позиций.',
                    'impact' => 'Снижение риска простоя и неравномерной загрузки бригад.',
                ],
            ],
        ];
    }

    private function buildWorkersHeuristics(array $data): array
    {
        $trend = (string) ($data['productivity']['productivity_trend'] ?? 'stable');
        $brigades = (int) ($data['brigade_analysis']['total_brigades'] ?? 0);
        $score = 75;

        if ($trend === 'declining') {
            $score -= 30;
        } elseif ($trend === 'improving') {
            $score += 10;
        }

        if ($brigades < 2) {
            $score -= 15;
        }

        $score = $this->normalizeScore($score);
        $status = $this->determineOverallStatus($score);

        return [
            'score' => $score,
            'status' => $status,
            'summary' => $trend === 'declining'
                ? 'Производительность бригад снижается.'
                : 'Текущая производительность бригад выглядит управляемой.',
            'analysis' => sprintf(
                'На проекте задействовано %d бригад. Средняя выработка: %.2f руб./день.',
                $brigades,
                (float) ($data['productivity']['value_per_day'] ?? 0)
            ),
            'recommendations' => [
                [
                    'priority' => $trend === 'declining' ? 'high' : 'medium',
                    'action' => 'Проверить загрузку и специализацию бригад по видам работ.',
                    'impact' => 'Стабилизация выработки и снижение простоев.',
                ],
            ],
        ];
    }

    private function buildContractsHeuristics(array $data): array
    {
        $total = (int) ($data['summary']['total_contracts'] ?? 0);
        $problem = (int) ($data['problem_contracts_count'] ?? 0);
        $score = $total > 0 ? $this->normalizeScore(100 - (($problem / $total) * 100 * 1.5)) : 100;
        $status = $this->determineOverallStatus($score);

        return [
            'score' => $score,
            'status' => $status,
            'summary' => $problem > 0
                ? "Проблемные контракты: {$problem} из {$total}."
                : 'Существенных отклонений по контрактам не выявлено.',
            'analysis' => sprintf(
                'Всего контрактов: %d. Оплачено %.2f руб. из %.2f руб.',
                $total,
                (float) ($data['summary']['total_paid'] ?? 0),
                (float) ($data['summary']['total_amount'] ?? 0)
            ),
            'recommendations' => [
                [
                    'priority' => $problem > 0 ? 'high' : 'low',
                    'action' => 'Разобрать просрочки и дисбаланс между оплатой и актированием.',
                    'impact' => 'Снижение финансовых и юридических рисков по договорам.',
                ],
            ],
        ];
    }

    private function buildRisksHeuristics(array $collectedData, array $analyses): array
    {
        $criticalSections = collect($analyses)
            ->filter(fn (array $analysis) => ($analysis['status'] ?? null) === 'critical')
            ->keys()
            ->values()
            ->all();

        $warningSections = collect($analyses)
            ->filter(fn (array $analysis) => ($analysis['status'] ?? null) === 'warning')
            ->keys()
            ->values()
            ->all();

        $riskScore = 85 - (count($criticalSections) * 18) - (count($warningSections) * 8);
        $score = $this->normalizeScore($riskScore);
        $status = $this->determineOverallStatus($score);

        return [
            'score' => $score,
            'status' => $status,
            'summary' => count($criticalSections) > 0
                ? 'Есть несколько риск-факторов с критичным уровнем влияния.'
                : 'Критические риски не доминируют, но есть зоны постоянного контроля.',
            'analysis' => 'Сводный риск рассчитывается по бюджету, срокам, материалам, рабочим ресурсам и контрактам.',
            'top_risks' => array_values(array_filter([
                (($collectedData['budget']['budget_health'] ?? null) === 'critical') ? [
                    'risk' => 'Перерасход бюджета',
                    'probability' => 80,
                    'impact' => 'high',
                    'mitigation' => 'Еженедельный контроль отклонений и пересмотр лимитов.',
                ] : null,
                (($collectedData['schedule']['schedule_health'] ?? null) === 'critical') ? [
                    'risk' => 'Срыв сроков',
                    'probability' => 75,
                    'impact' => 'high',
                    'mitigation' => 'Перепланировать критический путь и усилить контроль задач.',
                ] : null,
            ])),
            'overall_risk_level' => $status,
            'recommendations' => [
                [
                    'priority' => count($criticalSections) > 0 ? 'high' : 'medium',
                    'action' => 'Сконцентрировать управленческое внимание на критичных разделах анализа.',
                    'impact' => 'Быстрое снижение совокупного риска проекта.',
                ],
            ],
        ];
    }

    private function buildPerformanceHeuristics(array $data): array
    {
        $health = (float) ($data['project_health_index']['score'] ?? 50);
        $score = $this->normalizeScore($health);
        $status = $this->determineOverallStatus($score);

        return [
            'score' => $score,
            'status' => $status,
            'summary' => sprintf('Сводный индекс здоровья проекта: %.1f из 100.', $health),
            'analysis' => sprintf(
                'CPI: %.3f, SPI: %.3f. Материальная эффективность: %.1f. Управление контрактами: %.1f.',
                (float) ($data['cpi']['value'] ?? 0),
                (float) ($data['spi']['value'] ?? 0),
                (float) ($data['material_efficiency']['score'] ?? 0),
                (float) ($data['contract_management']['score'] ?? 0)
            ),
            'recommendations' => [
                [
                    'priority' => $score < 70 ? 'high' : 'medium',
                    'action' => 'Сверить фактические KPI проекта с планом по бюджету и срокам.',
                    'impact' => 'Быстрое выявление источника отклонения общей эффективности.',
                ],
            ],
        ];
    }

    private function buildRecommendationsHeuristics(array $collectedData, array $analyses): array
    {
        $criticalSections = collect($analyses)
            ->filter(fn (array $analysis) => ($analysis['status'] ?? null) === 'critical')
            ->map(fn (array $analysis, string $section) => $section)
            ->values()
            ->all();

        $warningSections = collect($analyses)
            ->filter(fn (array $analysis) => ($analysis['status'] ?? null) === 'warning')
            ->map(fn (array $analysis, string $section) => $section)
            ->values()
            ->all();

        $prioritySections = array_slice(array_merge($criticalSections, $warningSections), 0, 3);
        $score = $this->normalizeScore(100 - (count($criticalSections) * 20) - (count($warningSections) * 10));

        return [
            'score' => $score,
            'status' => $this->determineOverallStatus($score),
            'summary' => empty($prioritySections)
                ? 'Проект находится в управляемом состоянии, достаточно точечных улучшений.'
                : 'Приоритет рекомендаций сформирован по наиболее проблемным направлениям проекта.',
            'analysis' => empty($prioritySections)
                ? 'Критичных управленческих действий не требуется, акцент на профилактике отклонений.'
                : 'В фокусе: ' . implode(', ', $prioritySections) . '.',
            'critical_actions' => array_map(
                fn (string $section) => [
                    'action' => 'Подготовить план действий по разделу ' . $section,
                    'deadline' => now()->addDays(3)->format('Y-m-d'),
                    'priority' => in_array($section, $criticalSections, true) ? 'high' : 'medium',
                ],
                $prioritySections
            ),
            'optimization_opportunities' => [
                'Синхронизировать контроль бюджета, графика и поставок в одном цикле.',
                'Пересмотреть недельный план работ по критичным зонам.',
            ],
            'long_term_improvements' => [
                'Перейти на регулярный пересчет прогноза по срокам и стоимости.',
            ],
            'estimated_savings' => [
                'money' => (float) ($collectedData['materials']['total_purchase_cost'] ?? 0) * 0.1,
                'time_days' => count($criticalSections) > 0 ? 7 : 3,
            ],
            'recommendations' => [
                [
                    'priority' => count($criticalSections) > 0 ? 'high' : 'medium',
                    'action' => 'Утвердить антикризисный план по проблемным разделам.',
                    'impact' => 'Сокращение вероятности срыва бюджета и сроков.',
                ],
            ],
        ];
    }

    private function normalizeScore(float|int $score): int
    {
        return (int) max(0, min(100, round($score)));
    }

    private function normalizeStatus(string $status): string
    {
        return in_array($status, ['good', 'warning', 'critical'], true) ? $status : 'warning';
    }

    /**
     * Сохранить разделы анализа
     */
    private function createReportRecord(array $attributes): ?SystemAnalysisReport
    {
        $table = (new SystemAnalysisReport())->getTable();

        if (!$this->tableExists($table)) {
            $this->logging->technical('system_analysis.report_table_missing', [
                'table' => $table,
            ], 'warning');

            return null;
        }

        $persistedAttributes = $this->filterAttributesForTable($table, $attributes);

        if (!isset($persistedAttributes['organization_id'], $persistedAttributes['status'])) {
            return null;
        }

        try {
            return SystemAnalysisReport::create($persistedAttributes);
        } catch (Throwable $e) {
            $this->logging->technical('system_analysis.report_create_failed', [
                'error' => $e->getMessage(),
            ], 'warning');

            return null;
        }
    }

    private function updateReportRecord(?SystemAnalysisReport $report, array $attributes): void
    {
        if (!$report || !$report->exists || !$this->tableExists($report->getTable())) {
            return;
        }

        $persistedAttributes = $this->filterAttributesForTable($report->getTable(), $attributes);

        if ($persistedAttributes === []) {
            return;
        }

        try {
            $report->update($persistedAttributes);
        } catch (Throwable $e) {
            $this->logging->technical('system_analysis.report_update_failed', [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
            ], 'warning');
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    private function filterAttributesForTable(string $table, array $attributes): array
    {
        if (!$this->tableExists($table)) {
            return [];
        }

        $columns = Schema::getColumnListing($table);

        return array_filter(
            $attributes,
            static fn (string $attribute): bool => in_array($attribute, $columns, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function saveSections(?SystemAnalysisReport $report, array $collectedData, array $analyses): void
    {
        if (!$report || !$this->tableExists($report->getTable()) || !$this->tableExists((new SystemAnalysisSection())->getTable())) {
            return;
        }

        foreach ($analyses as $sectionType => $analysis) {
            $attributes = $this->filterAttributesForTable((new SystemAnalysisSection())->getTable(), [
                'report_id' => $report->id,
                'section_type' => $sectionType,
                'data' => $this->resolveSectionData($sectionType, $collectedData, $analyses),
                'analysis' => $analysis['analysis'] ?? '',
                'score' => $analysis['score'] ?? null,
                'status' => $analysis['status'] ?? null,
                'severity' => $this->determineSeverity($analysis['status'] ?? 'warning'),
                'recommendations' => $analysis['recommendations'] ?? [],
                'summary' => $analysis['summary'] ?? '',
            ]);

            if (!isset($attributes['report_id'], $attributes['section_type'])) {
                continue;
            }

            try {
                SystemAnalysisSection::create($attributes);
            } catch (Throwable $e) {
                $this->logging->technical('system_analysis.section_persist_failed', [
                    'report_id' => $report->id,
                    'section_type' => $sectionType,
                    'error' => $e->getMessage(),
                ], 'warning');
            }
        }
    }

    private function resolveSectionData(string $sectionType, array $collectedData, array $analyses): array
    {
        $recommendationsData = $this->buildRecommendationsHeuristics($collectedData, $analyses);

        return match ($sectionType) {
            'performance' => $collectedData['kpi'] ?? [],
            'risks' => [
                'budget_health' => $collectedData['budget']['budget_health'] ?? null,
                'schedule_health' => $collectedData['schedule']['schedule_health'] ?? null,
                'materials_health' => $collectedData['materials']['materials_health'] ?? null,
                'workers_health' => $collectedData['workers']['workers_health'] ?? null,
                'contracts_health' => $collectedData['contracts']['contracts_health'] ?? null,
            ],
            'recommendations' => [
                'critical_actions' => $recommendationsData['critical_actions'] ?? [],
                'optimization_opportunities' => $recommendationsData['optimization_opportunities'] ?? [],
                'long_term_improvements' => $recommendationsData['long_term_improvements'] ?? [],
                'estimated_savings' => $recommendationsData['estimated_savings'] ?? [],
            ],
            default => $collectedData[$sectionType] ?? [],
        };
    }

    /**
     * Сохранить историю
     */
    private function saveHistory(?SystemAnalysisReport $report): void
    {
        if (!$report || !$report->project_id || !$this->tableExists((new SystemAnalysisHistory())->getTable())) {
            return;
        }

        // Найти предыдущий анализ этого проекта
        $previousReport = SystemAnalysisReport::where('project_id', $report->project_id)
            ->where('id', '!=', $report->id)
            ->where('status', 'completed')
            ->latest('completed_at')
            ->first();

        if ($previousReport) {
            try {
                $comparison = $this->compareReports($report->id, $previousReport->id);
                $attributes = $this->filterAttributesForTable((new SystemAnalysisHistory())->getTable(), [
                    'report_id' => $report->id,
                    'previous_report_id' => $previousReport->id,
                    'changes' => $comparison['changes'],
                    'comparison' => $comparison,
                ]);

                if (isset($attributes['report_id'])) {
                    SystemAnalysisHistory::create($attributes);
                }
            } catch (Throwable $e) {
                $this->logging->technical('system_analysis.history_persist_failed', [
                    'report_id' => $report->id,
                    'previous_report_id' => $previousReport->id,
                    'error' => $e->getMessage(),
                ], 'warning');
            }
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
    private function formatAnalysisResult(?SystemAnalysisReport $report, array $collectedData, array $analyses): array
    {
        $project = $report?->project ?? Project::find($report?->project_id ?? null);
        $storedSections = $report && $this->tableExists((new SystemAnalysisSection())->getTable())
            ? $report->analysisSections()->get()
            : collect();

        return [
            'report_id' => $report?->id,
            'id' => $report?->id,
            'project' => [
                'id' => $project?->id,
                'name' => $project?->name ?? '',
                'status' => $project?->status,
            ],
            'analysis_type' => $report?->analysis_type ?? 'single_project',
            'generated_at' => $report?->completed_at ? $report->completed_at->toISOString() : now()->toISOString(),
            'overall_score' => $report?->overall_score ?? $this->calculateOverallScore($analyses),
            'overall_status' => $report?->overall_status ?? $this->determineOverallStatus($this->calculateOverallScore($analyses)),
            'tokens_used' => $report?->tokens_used ?? $this->calculateTokensUsed($analyses),
            'cost_rub' => $report?->cost ?? $this->calculateCost($analyses),
            'sections' => $this->formatSectionsForOutput($storedSections, $collectedData, $analyses),
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

        foreach ($analyses as $sectionType => $analysis) {
            if (isset($formatted[$sectionType])) {
                continue;
            }

            $formatted[$sectionType] = [
                'score' => $analysis['score'] ?? null,
                'status' => $analysis['status'] ?? null,
                'summary' => $analysis['summary'] ?? '',
                'analysis' => $analysis['analysis'] ?? '',
                'data' => $this->resolveSectionData($sectionType, $collectedData, $analyses),
                'recommendations' => $analysis['recommendations'] ?? [],
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
            'id' => $report->id,
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
            'tokens_used' => $report->tokens_used,
            'cost_rub' => $report->cost,
            'created_by' => $report->createdBy ? [
                'id' => $report->createdBy->id,
                'name' => $report->createdBy->name,
            ] : null,
            'sections' => $report->analysisSections->map(function ($section) {
                return [
                    'type' => $section->section_type,
                    'name' => $section->getSectionName(),
                    'icon' => $section->getSectionIcon(),
                    'score' => $section->score,
                    'status' => $section->status,
                    'summary' => $section->summary,
                    'analysis' => $section->analysis,
                    'data' => $section->data,
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
    private function getCachedValue(string $cacheKey, array $cacheTags): mixed
    {
        try {
            return Cache::tags($cacheTags)->get($cacheKey);
        } catch (Throwable $e) {
            $this->logging->technical('system_analysis.cache_read_fallback', [
                'cache_key' => $cacheKey,
                'error' => $e->getMessage(),
            ], 'warning');

            return Cache::get($cacheKey);
        }
    }

    private function putCachedValue(string $cacheKey, array $cacheTags, array $value, int $ttl): void
    {
        try {
            Cache::tags($cacheTags)->put($cacheKey, $value, $ttl);
        } catch (Throwable $e) {
            $this->logging->technical('system_analysis.cache_write_fallback', [
                'cache_key' => $cacheKey,
                'error' => $e->getMessage(),
            ], 'warning');

            Cache::put($cacheKey, $value, $ttl);
        }
    }

    private function forgetCachedValue(string $cacheKey, array $cacheTags): void
    {
        try {
            Cache::tags($cacheTags)->forget($cacheKey);
        } catch (Throwable $e) {
            $this->logging->technical('system_analysis.cache_forget_fallback', [
                'cache_key' => $cacheKey,
                'error' => $e->getMessage(),
            ], 'warning');

            Cache::forget($cacheKey);
        }
    }

    private function invalidateCache(int $projectId, int $organizationId): void
    {
        $cacheKey = "system_analysis:project:{$projectId}";
        $cacheTags = ['system_analysis', "project:{$projectId}", "org:{$organizationId}"];

        $this->forgetCachedValue($cacheKey, $cacheTags);
    }
}

