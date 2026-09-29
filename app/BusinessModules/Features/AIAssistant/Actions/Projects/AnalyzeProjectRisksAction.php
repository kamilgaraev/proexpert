<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Projects;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantLegacyFinancialRead;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyzeProjectRisksAction
{
    public function execute(int $organizationId, ?array $params = [], ?User $actor = null): array
    {
        if ($actor === null || ! app(AssistantDataAccessPolicy::class)->canReadDomain($actor, $organizationId, 'projects')
            || ! app(AssistantDataAccessPolicy::class)->canReadDomain($actor, $organizationId, 'finance')) {
            return [];
        }
        $projectId = $params['project_id'] ?? null;
        $finance = app(AssistantLegacyFinancialRead::class);
        $today = Carbon::today();
        $warningDays = 30;

        $projects = DB::table('projects')
            ->where('projects.organization_id', $organizationId)
            ->where('projects.status', '!=', 'completed')
            ->where('projects.status', '!=', 'cancelled')
            ->where('projects.is_archived', false)
            ->whereNull('projects.deleted_at')
            ->whereIn('projects.id', app(UserProjectAccessService::class)
                ->queryAccessibleProjects($actor, $organizationId)->select('projects.id'))
            ->when($projectId, function ($query, $id) {
                return $query->where('projects.id', $id);
            })
            ->select(
                'projects.id',
                'projects.name',
                'projects.status',
                'projects.budget_amount',
                'projects.start_date',
                'projects.end_date'
            )
            ->get();

        $deadlineRisks = [];
        $budgetRisks = [];
        $allRisks = [];
        $unassessedBudgets = [];

        foreach ($projects as $project) {
            $risks = [];
            $riskLevel = 'low';

            $budget = $finance->projectBudget($actor, $organizationId, $project->budget_amount);
            $source = $finance->projectSpent($actor, $organizationId, (int) $project->id);
            $spent = $source['spent'];
            $budgetPercentage = AssistantLegacyFinancialRead::percentage($spent, $budget);
            if ($budgetPercentage === null) {
                $unassessedBudgets[] = $project->id;
            }

            if ($project->end_date) {
                $endDate = Carbon::parse($project->end_date);
                $daysRemaining = $today->diffInDays($endDate, false);

                if ($daysRemaining < 0) {
                    $risks[] = 'Срок выполнения прошел на '.abs($daysRemaining).' дн.';
                    $riskLevel = 'high';
                    $deadlineRisks[] = [
                        'id' => $project->id,
                        'name' => $project->name,
                        'end_date' => $project->end_date,
                        'days_overdue' => abs($daysRemaining),
                    ];
                } elseif ($daysRemaining <= $warningDays) {
                    $risks[] = 'До дедлайна осталось '.$daysRemaining.' дн.';
                    if ($riskLevel === 'low') {
                        $riskLevel = 'medium';
                    }
                    $deadlineRisks[] = [
                        'id' => $project->id,
                        'name' => $project->name,
                        'end_date' => $project->end_date,
                        'days_remaining' => $daysRemaining,
                    ];
                }
            }

            if ($budget !== null && $spent !== null && BigDecimal::of($budget)->isGreaterThan(0) && BigDecimal::of($spent)->isGreaterThanOrEqualTo($budget)) {
                $risks[] = 'Бюджет превышен на '.AssistantLegacyFinancialRead::subtract($budgetPercentage, '100.00').'%';
                $riskLevel = 'high';
                $budgetRisks[] = [
                    'id' => $project->id,
                    'name' => $project->name,
                    'budget' => $budget,
                    'spent' => $spent,
                    'percentage_used' => $budgetPercentage,
                ];
            } elseif ($budget !== null && $spent !== null && BigDecimal::of($budget)->isGreaterThan(0) && BigDecimal::of($spent)->multipliedBy(100)->isGreaterThanOrEqualTo(BigDecimal::of($budget)->multipliedBy(80))) {
                $risks[] = 'Потрачено '.$budgetPercentage.'% бюджета';
                if ($riskLevel === 'low') {
                    $riskLevel = 'medium';
                }
                $budgetRisks[] = [
                    'id' => $project->id,
                    'name' => $project->name,
                    'budget' => $budget,
                    'spent' => $spent,
                    'percentage_used' => $budgetPercentage,
                ];
            }

            if (! empty($risks)) {
                $allRisks[] = [
                    'id' => $project->id,
                    'name' => $project->name,
                    'status' => $project->status,
                    'risk_level' => $riskLevel,
                    'risks' => $risks,
                    'budget_percentage' => $budgetPercentage,
                    'end_date' => $project->end_date,
                ];
            }
        }

        return [
            'projects_at_risk' => $unassessedBudgets === [] ? count($allRisks) : null,
            'deadline_risks_count' => count($deadlineRisks),
            'budget_risks_count' => $unassessedBudgets === [] ? count($budgetRisks) : null,
            'unassessed_budget_project_ids' => $unassessedBudgets,
            'deadline_risks' => $deadlineRisks,
            'budget_risks' => $budgetRisks,
            'all_risks' => $allRisks,
        ];
    }
}
