<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Services;

use App\BusinessModules\Features\Crm\Models\CrmActivity;
use App\BusinessModules\Features\Crm\Models\CrmCompany;
use App\BusinessModules\Features\Crm\Models\CrmContact;
use App\BusinessModules\Features\Crm\Models\CrmDeal;
use App\BusinessModules\Features\Crm\Models\CrmLead;
use App\BusinessModules\Features\Crm\Models\CrmPipelineStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function trans_message;

final class CrmWorkflowService
{
    public function __construct(
        private readonly CrmRegistryService $registry,
        private readonly CrmTimelineService $timeline
    ) {
    }

    public function qualifyLead(int $organizationId, string $leadId, ?int $actorUserId): CrmLead
    {
        $lead = $this->registry->findLead($organizationId, $leadId);

        if ($lead->status === 'converted') {
            throw ValidationException::withMessages([
                'status' => trans_message('crm.leads.already_converted'),
            ]);
        }

        $lead->update([
            'status' => 'qualified',
            'updated_by_user_id' => $actorUserId,
        ]);
        $this->timeline->record($organizationId, 'leads', $lead->id, 'qualified', trans_message('crm.timeline.lead_qualified'), $actorUserId);

        return $this->registry->findLead($organizationId, $lead->id);
    }

    public function convertLead(int $organizationId, string $leadId, array $data, ?int $actorUserId): array
    {
        return DB::transaction(function () use ($organizationId, $leadId, $data, $actorUserId): array {
            $lead = $this->registry->findLead($organizationId, $leadId);

            if ($lead->status === 'converted' || $lead->converted_deal_id !== null) {
                throw ValidationException::withMessages([
                    'lead' => trans_message('crm.leads.already_converted'),
                ]);
            }

            $company = $this->resolveConversionCompany($organizationId, $lead, $data, $actorUserId);
            $contact = $this->resolveConversionContact($organizationId, $lead, $company, $data, $actorUserId);
            $dealData = $data['deal'];
            $dealData['company_id'] = $company->id;
            $dealData['primary_contact_id'] = $contact?->id;
            $dealData['lead_id'] = $lead->id;
            $dealData['owner_user_id'] = $dealData['owner_user_id'] ?? $lead->owner_user_id;
            $dealData['source_id'] = $lead->source_id;
            $dealData['amount'] = $dealData['amount'] ?? $lead->estimated_amount;
            $deal = $this->registry->createDeal($organizationId, $dealData, $actorUserId);

            $lead->update([
                'company_id' => $company->id,
                'contact_id' => $contact?->id,
                'converted_deal_id' => $deal->id,
                'status' => 'converted',
                'converted_at' => now(),
                'updated_by_user_id' => $actorUserId,
            ]);
            $this->timeline->record($organizationId, 'leads', $lead->id, 'converted', trans_message('crm.timeline.lead_converted'), $actorUserId, [
                'deal_id' => $deal->id,
            ]);
            $this->timeline->record($organizationId, 'deals', $deal->id, 'created_from_lead', trans_message('crm.timeline.deal_created_from_lead'), $actorUserId, [
                'lead_id' => $lead->id,
            ]);

            return [
                'lead' => $this->registry->findLead($organizationId, $lead->id),
                'company' => $this->registry->findCompany($organizationId, $company->id),
                'contact' => $contact === null ? null : $this->registry->findContact($organizationId, $contact->id),
                'deal' => $this->registry->findDeal($organizationId, $deal->id),
            ];
        });
    }

    public function transitionDeal(int $organizationId, string $dealId, array $data, ?int $actorUserId): CrmDeal
    {
        return DB::transaction(function () use ($organizationId, $dealId, $data, $actorUserId): CrmDeal {
            $deal = $this->registry->findDeal($organizationId, $dealId);
            $data = $this->registry->validateDealReferences($organizationId, $data, $deal);
            $stage = null;

            if (!empty($data['stage_id'])) {
                $stage = CrmPipelineStage::query()
                    ->whereKey($data['stage_id'])
                    ->whereHas('pipeline', function ($query) use ($organizationId): void {
                        $query->where('organization_id', $organizationId)->orWhereNull('organization_id');
                    })
                    ->with('pipeline')
                    ->firstOrFail();
            }

            $status = $data['status'] ?? $deal->status;
            $stageCode = $data['stage_code'] ?? $stage?->code ?? $deal->stage_code;
            $pipelineCode = $data['pipeline_code'] ?? $stage?->pipeline?->code ?? $deal->pipeline_code;

            if ($stage !== null && in_array($stage->category, ['won', 'lost'], true)) {
                $status = $stage->category;
            }

            $attributes = [
                'pipeline_id' => $data['pipeline_id'] ?? $stage?->pipeline_id ?? $deal->pipeline_id,
                'stage_id' => $stage?->id ?? ($data['stage_id'] ?? $deal->stage_id),
                'pipeline_code' => $pipelineCode,
                'stage_code' => $stageCode,
                'status' => $status,
                'probability' => $data['probability'] ?? $stage?->probability_percent ?? $deal->probability,
                'lost_reason' => $data['lost_reason'] ?? $deal->lost_reason,
                'updated_by_user_id' => $actorUserId,
            ];

            if ($status === 'won') {
                $attributes['won_at'] = $deal->won_at ?? now();
                $attributes['lost_at'] = null;
                $attributes['lost_reason'] = null;
                $attributes['probability'] = 100;
            }

            if ($status === 'lost') {
                $attributes['lost_at'] = $deal->lost_at ?? now();
                $attributes['won_at'] = null;
                $attributes['probability'] = 0;
            }

            $deal->update($attributes);
            $this->timeline->record($organizationId, 'deals', $deal->id, 'stage_changed', trans_message('crm.timeline.deal_stage_changed'), $actorUserId, [
                'stage_code' => $stageCode,
                'status' => $status,
            ]);

            return $this->registry->findDeal($organizationId, $deal->id);
        });
    }

    public function completeActivity(int $organizationId, string $activityId, array $data, ?int $actorUserId): CrmActivity
    {
        $activity = $this->registry->findActivity($organizationId, $activityId);
        $activity->update([
            'status' => 'done',
            'completed_at' => $data['completed_at'] ?? now(),
            'result' => $data['result'] ?? $activity->result,
            'updated_by_user_id' => $actorUserId,
        ]);
        $this->timeline->record($organizationId, 'activities', $activity->id, 'completed', trans_message('crm.timeline.activity_completed'), $actorUserId);

        return $this->registry->findActivity($organizationId, $activity->id);
    }

    public function linkDeal(int $organizationId, string $dealId, array $data, ?int $actorUserId): CrmDeal
    {
        $deal = $this->registry->findDeal($organizationId, $dealId);
        $data = $this->registry->validateDealReferences($organizationId, $data, $deal);
        $deal->update([
            'project_id' => array_key_exists('project_id', $data) ? $data['project_id'] : $deal->project_id,
            'contract_id' => array_key_exists('contract_id', $data) ? $data['contract_id'] : $deal->contract_id,
            'updated_by_user_id' => $actorUserId,
        ]);
        $this->timeline->record($organizationId, 'deals', $deal->id, 'linked', trans_message('crm.timeline.deal_linked'), $actorUserId);

        return $this->registry->findDeal($organizationId, $deal->id);
    }

    private function resolveConversionCompany(int $organizationId, CrmLead $lead, array $data, ?int $actorUserId): CrmCompany
    {
        if (!empty($data['company_id'])) {
            return $this->registry->findCompany($organizationId, $data['company_id']);
        }

        if ($lead->company_id !== null) {
            return $this->registry->findCompany($organizationId, $lead->company_id);
        }

        $companyData = $data['company'] ?? [
            'name' => $lead->title,
        ];

        return $this->registry->createCompany($organizationId, $companyData, $actorUserId);
    }

    private function resolveConversionContact(
        int $organizationId,
        CrmLead $lead,
        CrmCompany $company,
        array $data,
        ?int $actorUserId
    ): ?CrmContact {
        if (!empty($data['contact_id'])) {
            return $this->registry->findContact($organizationId, $data['contact_id']);
        }

        if ($lead->contact_id !== null) {
            return $this->registry->findContact($organizationId, $lead->contact_id);
        }

        if (empty($data['contact']['full_name'])) {
            return null;
        }

        $contactData = $data['contact'];
        $contactData['company_id'] = $company->id;
        $contactData['is_primary'] = true;

        return $this->registry->createContact($organizationId, $contactData, $actorUserId);
    }
}
