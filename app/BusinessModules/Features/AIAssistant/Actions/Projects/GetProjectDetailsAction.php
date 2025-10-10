<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Projects;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class GetProjectDetailsAction
{
    public function execute(int $organizationId, ?array $params = []): array
    {
        $projectId = $params['project_id'] ?? null;
        
        if (!$projectId) {
            return ['error' => 'Project ID not specified'];
        }

        $project = DB::table('projects')
            ->leftJoin('cost_categories', 'projects.cost_category_id', '=', 'cost_categories.id')
            ->where('projects.id', $projectId)
            ->where('projects.organization_id', $organizationId)
            ->whereNull('projects.deleted_at')
            ->select(
                'projects.*',
                'cost_categories.name as cost_category_name'
            )
            ->first();

        if (!$project) {
            return ['error' => 'Project not found'];
        }

        $budget = DB::table('completed_works')
            ->where('project_id', $projectId)
            ->where('status', 'confirmed')
            ->whereNull('deleted_at')
            ->select(
                DB::raw('COALESCE(SUM(total_amount), 0) as spent'),
                DB::raw('COUNT(*) as works_count')
            )
            ->first();

        $materialStats = DB::table('material_balances')
            ->where('project_id', $projectId)
            ->select(
                DB::raw('SUM(available_quantity) as total_materials'),
                DB::raw('SUM(reserved_quantity) as reserved_materials'),
                DB::raw('COUNT(DISTINCT material_id) as materials_types')
            )
            ->first();

        $teamMembers = DB::table('project_user')
            ->join('users', 'project_user.user_id', '=', 'users.id')
            ->where('project_user.project_id', $projectId)
            ->select(
                'users.id',
                'users.name',
                'users.email',
                'project_user.role'
            )
            ->get();

        $contracts = DB::table('contracts')
            ->join('contractors', 'contracts.contractor_id', '=', 'contractors.id')
            ->where('contracts.project_id', $projectId)
            ->whereNull('contracts.deleted_at')
            ->select(
                'contracts.id',
                'contracts.number',
                'contracts.status',
                'contracts.total_amount',
                'contracts.date',
                'contracts.start_date',
                'contracts.end_date',
                'contractors.name as contractor_name'
            )
            ->get();

        $spent = (float)$budget->spent;
        $budgetAmount = (float)($project->budget_amount ?? 0);
        $remaining = $budgetAmount - $spent;
        $percentageUsed = $budgetAmount > 0 ? round(($spent / $budgetAmount) * 100, 2) : 0;

        $today = Carbon::today();
        $daysRemaining = null;
        $isOverdue = false;
        
        if ($project->end_date) {
            $endDate = Carbon::parse($project->end_date);
            $daysRemaining = $today->diffInDays($endDate, false);
            $isOverdue = $daysRemaining < 0;
        }

        return [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status,
                'address' => $project->address,
                'description' => $project->description,
                'customer' => $project->customer,
                'customer_organization' => $project->customer_organization,
                'customer_representative' => $project->customer_representative,
                'designer' => $project->designer,
                'contract_number' => $project->contract_number,
                'contract_date' => $project->contract_date,
                'start_date' => $project->start_date,
                'end_date' => $project->end_date,
                'days_remaining' => $daysRemaining,
                'is_overdue' => $isOverdue,
                'site_area_m2' => (float)($project->site_area_m2 ?? 0),
                'cost_category' => $project->cost_category_name,
                'is_archived' => (bool)$project->is_archived,
                'is_head' => (bool)$project->is_head,
            ],
            'budget' => [
                'total_budget' => $budgetAmount,
                'spent' => $spent,
                'remaining' => $remaining,
                'percentage_used' => $percentageUsed,
                'works_count' => (int)$budget->works_count,
            ],
            'materials' => [
                'total_quantity' => (float)($materialStats->total_materials ?? 0),
                'reserved_quantity' => (float)($materialStats->reserved_materials ?? 0),
                'types_count' => (int)($materialStats->materials_types ?? 0),
            ],
            'team_members' => $teamMembers->map(function($member) {
                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => $member->role,
                ];
            })->toArray(),
            'contracts' => $contracts->map(function($contract) {
                return [
                    'id' => $contract->id,
                    'number' => $contract->number,
                    'status' => $contract->status,
                    'total_amount' => (float)$contract->total_amount,
                    'date' => $contract->date,
                    'start_date' => $contract->start_date,
                    'end_date' => $contract->end_date,
                    'contractor_name' => $contract->contractor_name,
                ];
            })->toArray(),
        ];
    }
}

