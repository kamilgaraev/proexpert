<?php

namespace App\BusinessModules\Core\MultiOrganization\Listeners;

use App\Events\ProjectCreated;
use App\Models\Organization;
use App\Models\Contractor;
use App\Modules\Core\AccessController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoAddParentToProject
{
    public function __construct(
        private AccessController $accessController
    ) {
    }

    public function handle(ProjectCreated $event): void
    {
        $project = $event->project;
        $org = Organization::find($project->organization_id);

        if (!$org) {
            return;
        }

        if (!$this->accessController->hasModuleAccess($org->id, 'multi-organization')) {
            return;
        }

        if (!$org->parent_organization_id) {
            return;
        }

        $exists = DB::table('project_organization')
            ->where('project_id', $project->id)
            ->where('organization_id', $org->parent_organization_id)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('project_organization')->insert([
            'project_id' => $project->id,
            'organization_id' => $org->parent_organization_id,
            'role' => 'parent_administrator',
            'role_new' => 'parent_administrator',
            'is_active' => true,
            'invited_at' => now(),
            'accepted_at' => now(),
            'metadata' => json_encode([
                'auto_added' => true,
                'reason' => 'parent_organization',
                'child_organization_id' => $org->id,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('Parent organization auto-added to project', [
            'project_id' => $project->id,
            'project_name' => $project->name,
            'parent_org_id' => $org->parent_organization_id,
            'child_org_id' => $org->id,
        ]);

        $this->ensureContractorExists($org->id, $org->parent_organization_id);
    }

    private function ensureContractorExists(int $forOrgId, int $sourceOrgId): void
    {
        $sourceOrg = Organization::find($sourceOrgId);
        if (!$sourceOrg) {
            return;
        }

        $exists = Contractor::where('organization_id', $forOrgId)
            ->where('source_organization_id', $sourceOrgId)
            ->exists();

        if ($exists) {
            return;
        }

        Contractor::create([
            'organization_id' => $forOrgId,
            'source_organization_id' => $sourceOrgId,
            'name' => $sourceOrg->name,
            'inn' => $sourceOrg->tax_number,
            'legal_address' => $sourceOrg->address,
            'phone' => $sourceOrg->phone,
            'email' => $sourceOrg->email,
            'contractor_type' => Contractor::TYPE_INVITED_ORGANIZATION,
            'sync_settings' => [
                'sync_fields' => ['name', 'phone', 'email', 'legal_address'],
                'sync_interval_hours' => 24,
            ],
        ]);
    }
}

