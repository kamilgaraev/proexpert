<?php

namespace App\BusinessModules\Core\MultiOrganization\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Organization;

class OrganizationMetrics extends Model
{
    protected $table = 'organization_metrics';
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = 'organization_id';

    protected $casts = [
        'projects_count' => 'integer',
        'projects_active' => 'integer',
        'projects_completed' => 'integer',
        'total_budget' => 'decimal:2',
        'contracts_count' => 'integer',
        'total_contract_amount' => 'decimal:2',
        'active_contract_amount' => 'decimal:2',
        'users_count' => 'integer',
        'is_holding' => 'boolean',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public static function getHoldingMetrics(int $holdingId): array
    {
        $metrics = self::query()
            ->where(function($q) use ($holdingId) {
                $q->where('organization_id', $holdingId)
                  ->orWhere('parent_organization_id', $holdingId);
            })
            ->get();

        return [
            'total' => [
                'projects' => $metrics->sum('projects_count'),
                'active_projects' => $metrics->sum('projects_active'),
                'completed_projects' => $metrics->sum('projects_completed'),
                'budget' => $metrics->sum('total_budget'),
                'contracts' => $metrics->sum('contracts_count'),
                'contract_amount' => $metrics->sum('total_contract_amount'),
                'users' => $metrics->sum('users_count'),
            ],
            'by_organization' => $metrics->map(fn($m) => [
                'org_id' => $m->organization_id,
                'org_name' => $m->organization_name,
                'is_holding' => $m->is_holding,
                'projects' => $m->projects_count,
                'budget' => (float) $m->total_budget,
                'contracts' => $m->contracts_count,
                'users' => $m->users_count,
            ])->toArray(),
            'last_update' => $metrics->max('last_project_update'),
            'calculated_at' => now(),
        ];
    }
}

