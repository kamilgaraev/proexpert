<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Services;

use App\BusinessModules\Core\MultiOrganization\Contracts\ContractorSharingInterface;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposal;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalTimelineEvent;
use App\BusinessModules\Features\Crm\Exceptions\DealConversionException;
use App\BusinessModules\Features\Crm\Models\CrmConversionOperation;
use App\BusinessModules\Features\Crm\Models\CrmDeal;
use App\BusinessModules\Features\Tenders\Models\Tender;
use App\BusinessModules\Features\Tenders\Services\TenderTimelineService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\DTOs\Contract\ContractDTO;
use App\DTOs\Project\ProjectDTO;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Contract\ContractService;
use App\Services\Logging\LoggingService;
use App\Services\Project\ProjectService;
use BackedEnum;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

use function trans_message;

final class DealConversionWizardService
{
    private const CONTRACTOR_SIDE_TYPES = [
        'customer_to_general_contractor',
        'general_contractor_to_contractor',
        'contractor_to_subcontractor',
    ];

    private const SUPPLIER_SIDE_TYPES = [
        'general_contractor_to_supplier',
        'contractor_to_supplier',
        'subcontractor_to_supplier',
    ];

    public function __construct(
        private readonly ProjectService $projectService,
        private readonly ContractService $contractService,
        private readonly CrmTimelineService $crmTimeline,
        private readonly TenderTimelineService $tenderTimeline,
        private readonly AuthorizationService $authorization,
        private readonly ContractorSharingInterface $contractorSharing,
        private readonly LoggingService $logging
    ) {}

    public function preview(int $organizationId, string $dealId, array $data, User $user): array
    {
        $context = $this->buildContext($organizationId, $dealId, $data);

        return $this->buildPreview($organizationId, $context, $data, $user);
    }

    public function validateConversion(int $organizationId, string $dealId, array $data, User $user): array
    {
        $context = $this->buildContext($organizationId, $dealId, $data);

        return $this->buildPreview($organizationId, $context, $data, $user, true);
    }

    public function convert(int $organizationId, string $dealId, array $data, Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new DealConversionException(trans_message('auth.unauthorized'), 401);
        }

        $idempotencyKey = (string) $data['idempotency_key'];
        $payloadHash = $this->payloadHash($data);

        return DB::transaction(function () use ($organizationId, $dealId, $data, $request, $user, $idempotencyKey, $payloadHash): array {
            $context = $this->buildContext($organizationId, $dealId, $data, true);
            $deal = $context['deal'];

            $operation = CrmConversionOperation::query()
                ->where('organization_id', $organizationId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($operation instanceof CrmConversionOperation) {
                if ($operation->payload_hash !== $payloadHash) {
                    throw new DealConversionException(
                        trans_message('crm.conversion.errors.idempotency_conflict'),
                        409,
                        [['key' => 'idempotency_conflict', 'label' => trans_message('crm.conversion.blockers.idempotency_conflict')]]
                    );
                }

                if ($operation->status === 'completed') {
                    return array_merge($operation->result_snapshot ?? [], [
                        'status' => 'already_converted',
                        'idempotent_replay' => true,
                    ]);
                }
            }

            $completedOperation = CrmConversionOperation::query()
                ->where('organization_id', $organizationId)
                ->where('crm_deal_id', $deal->id)
                ->where('status', 'completed')
                ->lockForUpdate()
                ->first();

            if ($completedOperation instanceof CrmConversionOperation) {
                return array_merge($completedOperation->result_snapshot ?? [], [
                    'status' => 'already_converted',
                    'idempotent_replay' => false,
                ]);
            }

            $preview = $this->buildPreview($organizationId, $context, $data, $user, true);

            if (! $preview['ready_to_convert']) {
                throw new DealConversionException(
                    trans_message('crm.conversion.errors.validation_failed'),
                    409,
                    $preview['blockers'],
                    $preview['warnings']
                );
            }

            if (! $operation instanceof CrmConversionOperation) {
                $operation = CrmConversionOperation::query()->create([
                    'organization_id' => $organizationId,
                    'idempotency_key' => $idempotencyKey,
                    'crm_deal_id' => $deal->id,
                    'tender_id' => $context['tender']?->id,
                    'commercial_proposal_id' => $context['commercial_proposal']?->id,
                    'payload_hash' => $payloadHash,
                    'preview_hash' => $preview['preview_hash'],
                    'status' => 'started',
                    'created_by_user_id' => $user->id,
                    'result_snapshot' => [],
                ]);
            }

            try {
                $project = $this->resolveProjectForConvert($organizationId, $preview, $data, $request);
                $contract = $this->resolveContractForConvert($organizationId, $preview, $data, $project);

                $this->attachCreatedObjects($organizationId, $context, $project, $contract, $user->id);
                $this->recordConversionEvents($organizationId, $context, $project, $contract, $user->id);

                $result = $this->buildConvertResult($operation->id, $context, $project, $contract, $preview['warnings']);

                $operation->update([
                    'status' => 'completed',
                    'project_id' => $project->id,
                    'contract_id' => $contract->id,
                    'result_snapshot' => $result,
                    'completed_at' => now(),
                    'error_code' => null,
                ]);

                $this->logging->audit('crm.deal_conversion.completed', [
                    'organization_id' => $organizationId,
                    'crm_deal_id' => $deal->id,
                    'tender_id' => $context['tender']?->id,
                    'commercial_proposal_id' => $context['commercial_proposal']?->id,
                    'project_id' => $project->id,
                    'contract_id' => $contract->id,
                    'performed_by' => $user->id,
                ]);

                return $result;
            } catch (Throwable $exception) {
                $operation->update([
                    'status' => 'failed',
                    'error_code' => 'conversion_failed',
                ]);

                throw $exception;
            }
        });
    }

    private function buildContext(int $organizationId, string $dealId, array $data, bool $lock = false): array
    {
        $dealQuery = CrmDeal::query()
            ->forOrganization($organizationId)
            ->with(['company.linkedContractor', 'primaryContact', 'project', 'contract']);

        if ($lock) {
            $dealQuery->lockForUpdate();
        }

        $deal = $dealQuery->findOrFail($dealId);
        $tender = $this->resolveTender($organizationId, $deal, $data, $lock);
        $proposal = $this->resolveCommercialProposal($organizationId, $deal, $tender, $data, $lock);

        return [
            'deal' => $deal,
            'tender' => $tender,
            'commercial_proposal' => $proposal,
            'tender_candidates' => $this->tenderCandidates($organizationId, $deal->id),
            'commercial_proposal_candidates' => $this->commercialProposalCandidates($organizationId, $deal->id, $tender?->id),
        ];
    }

    private function buildPreview(
        int $organizationId,
        array $context,
        array $data,
        User $user,
        bool $includeAuthorization = false
    ): array {
        $deal = $context['deal'];
        $tender = $context['tender'];
        $proposal = $context['commercial_proposal'];
        $warnings = [];
        $blockers = [];
        $amount = $this->resolveAmount($organizationId, $deal, $tender, $proposal, $user);
        $project = $this->buildProjectPreview($organizationId, $context, $data, $amount);
        $contract = $this->buildContractPreview($organizationId, $context, $data, $amount);

        $this->appendSourceBlockers($context, $blockers, $warnings);
        $this->appendLinkBlockers($context, $project, $contract, $blockers);
        $this->appendRequiredFieldBlockers($project['missing_fields'], $contract['missing_fields'], $blockers);

        if ($includeAuthorization) {
            $this->appendAuthorizationBlockers($organizationId, $user, $context, $blockers);
        }

        $preview = [
            'deal' => $this->dealSummary($deal),
            'source_chain' => [
                'tender' => $this->tenderSummary($tender),
                'commercial_proposal' => $this->commercialProposalSummary($proposal),
                'tender_candidates' => $context['tender_candidates'],
                'commercial_proposal_candidates' => $context['commercial_proposal_candidates'],
            ],
            'amount' => $amount,
            'project' => $project,
            'contract' => $contract,
            'documents' => $this->documentReferences($tender, $proposal),
            'budget_seed' => $this->budgetSeed($data, $amount, $proposal, $tender),
            'warnings' => $warnings,
            'blockers' => $blockers,
            'ready_to_convert' => $blockers === [],
        ];

        $preview['preview_hash'] = $this->previewHash($preview);

        if (! empty($data['preview_hash']) && $data['preview_hash'] !== $preview['preview_hash']) {
            $preview['blockers'][] = [
                'key' => 'preview_changed',
                'label' => trans_message('crm.conversion.blockers.preview_changed'),
            ];
            $preview['ready_to_convert'] = false;
        }

        return $preview;
    }

    private function resolveProjectForConvert(int $organizationId, array $preview, array $data, Request $request): Project
    {
        if ($preview['project']['mode'] === 'reuse') {
            $projectId = (int) ($preview['project']['existing']['id'] ?? $data['project']['id'] ?? 0);
            $project = Project::query()
                ->whereKey($projectId)
                ->where('organization_id', $organizationId)
                ->first();

            if (! $project instanceof Project) {
                throw new ModelNotFoundException;
            }

            return $project;
        }

        $fields = $preview['project']['fields'];

        return $this->projectService->createProject(new ProjectDTO(
            name: (string) $fields['name'],
            address: $fields['address'] ?? null,
            latitude: null,
            longitude: null,
            description: $fields['description'] ?? null,
            customer: $fields['customer'] ?? null,
            designer: null,
            budget_amount: $this->nullableFloat($fields['budget_amount'] ?? null),
            site_area_m2: null,
            contract_number: $fields['contract_number'] ?? null,
            start_date: $fields['start_date'] ?? null,
            end_date: $fields['end_date'] ?? null,
            status: (string) $fields['status'],
            is_archived: false,
            additional_info: $fields['additional_info'] ?? [],
            external_code: null,
            cost_category_id: isset($fields['cost_category_id']) ? (int) $fields['cost_category_id'] : null,
            accounting_data: null,
            use_in_accounting_reports: false
        ), $request);
    }

    private function resolveContractForConvert(int $organizationId, array $preview, array $data, Project $project): Contract
    {
        if ($preview['contract']['mode'] === 'reuse') {
            $contractId = (int) ($preview['contract']['existing']['id'] ?? $data['contract']['id'] ?? 0);
            $contract = Contract::query()
                ->whereKey($contractId)
                ->where('organization_id', $organizationId)
                ->first();

            if (! $contract instanceof Contract) {
                throw new ModelNotFoundException;
            }

            return $contract;
        }

        $fields = $preview['contract']['fields'];
        $sideType = ContractSideTypeEnum::from((string) $fields['contract_side_type']);
        $status = ContractStatusEnum::from((string) $fields['status']);
        $baseAmount = $this->nullableFloat($fields['base_amount'] ?? null);
        $totalAmount = $this->nullableFloat($fields['total_amount'] ?? null) ?? $baseAmount;

        return $this->contractService->createContract($organizationId, new ContractDTO(
            project_id: $project->id,
            contractor_id: isset($fields['contractor_id']) ? (int) $fields['contractor_id'] : null,
            parent_contract_id: null,
            number: (string) $fields['number'],
            date: (string) $fields['date'],
            subject: $fields['subject'] ?? null,
            work_type_category: null,
            payment_terms: null,
            base_amount: $baseAmount,
            total_amount: $totalAmount,
            gp_percentage: null,
            gp_calculation_type: null,
            gp_coefficient: null,
            warranty_retention_calculation_type: null,
            warranty_retention_percentage: null,
            warranty_retention_coefficient: null,
            subcontract_amount: null,
            planned_advance_amount: null,
            actual_advance_amount: null,
            status: $status,
            start_date: $fields['start_date'] ?? null,
            end_date: $fields['end_date'] ?? null,
            notes: $fields['notes'] ?? null,
            advance_payments: null,
            is_fixed_amount: (bool) ($fields['is_fixed_amount'] ?? true),
            is_multi_project: false,
            project_ids: null,
            is_self_execution: false,
            supplier_id: isset($fields['supplier_id']) ? (int) $fields['supplier_id'] : null,
            contract_category: null,
            contract_side_type: $sideType
        ));
    }

    private function attachCreatedObjects(int $organizationId, array $context, Project $project, Contract $contract, int $actorUserId): void
    {
        $deal = $context['deal'];
        $tender = $context['tender'];
        $proposal = $context['commercial_proposal'];

        $deal->update([
            'project_id' => $project->id,
            'contract_id' => $contract->id,
            'updated_by_user_id' => $actorUserId,
        ]);

        if ($tender instanceof Tender) {
            $tender->update([
                'crm_deal_id' => $tender->crm_deal_id ?? $deal->id,
                'commercial_proposal_id' => $proposal?->id ?? $tender->commercial_proposal_id,
                'project_id' => $project->id,
                'contract_id' => $contract->id,
                'updated_by_user_id' => $actorUserId,
            ]);
        }

        if ($proposal instanceof CommercialProposal) {
            $proposal->update([
                'crm_deal_id' => $proposal->crm_deal_id ?? $deal->id,
                'tender_id' => $tender?->id ?? $proposal->tender_id,
                'project_id' => $project->id,
                'contract_id' => $contract->id,
                'updated_by_user_id' => $actorUserId,
            ]);
        }

        $this->logging->business('crm.deal_conversion.links_updated', [
            'organization_id' => $organizationId,
            'crm_deal_id' => $deal->id,
            'project_id' => $project->id,
            'contract_id' => $contract->id,
        ]);
    }

    private function recordConversionEvents(int $organizationId, array $context, Project $project, Contract $contract, int $actorUserId): void
    {
        $deal = $context['deal'];
        $tender = $context['tender'];
        $proposal = $context['commercial_proposal'];
        $metadata = [
            'project_id' => $project->id,
            'contract_id' => $contract->id,
            'tender_id' => $tender?->id,
            'commercial_proposal_id' => $proposal?->id,
        ];

        $this->crmTimeline->record(
            $organizationId,
            'deals',
            $deal->id,
            'conversion_completed',
            trans_message('crm.conversion.timeline.completed'),
            $actorUserId,
            $metadata
        );

        if ($tender instanceof Tender) {
            $this->tenderTimeline->record(
                $organizationId,
                $tender->id,
                'conversion_completed',
                trans_message('crm.conversion.timeline.tender_completed'),
                $actorUserId,
                $metadata
            );
        }

        if ($proposal instanceof CommercialProposal) {
            CommercialProposalTimelineEvent::query()->create([
                'organization_id' => $organizationId,
                'commercial_proposal_id' => $proposal->id,
                'commercial_proposal_version_id' => $proposal->accepted_version_id ?? $proposal->current_version_id,
                'actor_user_id' => $actorUserId,
                'event_type' => 'conversion_completed',
                'from_status' => null,
                'to_status' => $this->enumValue($proposal->status),
                'payload' => $metadata + ['message' => trans_message('crm.conversion.timeline.commercial_proposal_completed')],
                'occurred_at' => now(),
            ]);
        }
    }

    private function buildConvertResult(string $operationId, array $context, Project $project, Contract $contract, array $warnings): array
    {
        return [
            'status' => 'converted',
            'idempotent_replay' => false,
            'conversion_id' => $operationId,
            'deal' => [
                'id' => $context['deal']->id,
                'title' => $context['deal']->title,
                'project_id' => $project->id,
                'contract_id' => $contract->id,
            ],
            'project' => $this->projectSummary($project),
            'contract' => $this->contractSummary($contract),
            'source_links' => [
                'tender_id' => $context['tender']?->id,
                'commercial_proposal_id' => $context['commercial_proposal']?->id,
            ],
            'warnings' => $warnings,
            'next_actions' => [
                [
                    'key' => 'open_project',
                    'label' => trans_message('crm.conversion.actions.open_project'),
                    'path' => "/projects/{$project->id}",
                ],
                [
                    'key' => 'open_contract',
                    'label' => trans_message('crm.conversion.actions.open_contract'),
                    'path' => "/projects/{$project->id}/contracts/{$contract->id}",
                ],
            ],
        ];
    }

    private function buildProjectPreview(int $organizationId, array $context, array $data, array $amount): array
    {
        $deal = $context['deal'];
        $tender = $context['tender'];
        $proposal = $context['commercial_proposal'];
        $input = $data['project'] ?? [];
        $inputFields = $input['fields'] ?? [];
        $existingId = $input['id']
            ?? $deal->project_id
            ?? $tender?->project_id
            ?? $proposal?->project_id;
        $mode = $input['mode'] ?? ($existingId ? 'reuse' : 'create');
        $existing = $existingId ? $this->findProjectSummary($organizationId, (int) $existingId) : null;
        $fields = [
            'name' => $inputFields['name'] ?? $proposal?->title ?? $tender?->title ?? $deal->title,
            'description' => $inputFields['description'] ?? $tender?->description,
            'customer' => $inputFields['customer'] ?? $deal->company?->legal_name ?? $deal->company?->name ?? $tender?->customer_name ?? $proposal?->customer_name,
            'address' => $inputFields['address'] ?? $deal->company?->actual_address ?? $deal->company?->legal_address,
            'start_date' => $inputFields['start_date'] ?? null,
            'end_date' => $inputFields['end_date'] ?? null,
            'status' => $inputFields['status'] ?? 'draft',
            'budget_amount' => $inputFields['budget_amount'] ?? ($amount['amount_visible'] ? $amount['value'] : null),
            'contract_number' => $inputFields['contract_number'] ?? $proposal?->number ?? $tender?->number,
            'cost_category_id' => $inputFields['cost_category_id'] ?? null,
            'additional_info' => [
                'source' => 'crm_conversion',
                'crm_deal_id' => $deal->id,
                'tender_id' => $tender?->id,
                'commercial_proposal_id' => $proposal?->id,
            ],
        ];
        $missing = [];

        if ($mode === 'reuse' && ! $existing) {
            $missing[] = 'project_id';
        }

        if ($mode === 'create') {
            foreach (['name', 'status'] as $field) {
                if ($this->blank($fields[$field] ?? null)) {
                    $missing[] = $field;
                }
            }
        }

        return [
            'mode' => $mode,
            'existing' => $existing,
            'fields' => $fields,
            'required_fields' => $mode === 'create' ? ['name', 'status'] : ['project_id'],
            'missing_fields' => $missing,
        ];
    }

    private function buildContractPreview(int $organizationId, array $context, array $data, array $amount): array
    {
        $deal = $context['deal'];
        $tender = $context['tender'];
        $proposal = $context['commercial_proposal'];
        $input = $data['contract'] ?? [];
        $inputFields = $input['fields'] ?? [];
        $counterparty = $this->resolveCounterparty($organizationId, $deal, $data);
        $existingId = $input['id']
            ?? $deal->contract_id
            ?? $tender?->contract_id
            ?? $proposal?->contract_id;
        $mode = $input['mode'] ?? ($existingId ? 'reuse' : 'create');
        $existing = $existingId ? $this->findContractSummary($organizationId, (int) $existingId) : null;
        $sideType = $inputFields['contract_side_type'] ?? 'customer_to_general_contractor';
        $isFixedAmount = (bool) ($inputFields['is_fixed_amount'] ?? $amount['amount_visible']);
        $baseAmount = $inputFields['base_amount'] ?? ($amount['amount_visible'] ? $amount['value'] : null);
        $supplierId = in_array($sideType, self::SUPPLIER_SIDE_TYPES, true)
            ? $this->resolveSupplierId($organizationId, $data['counterparty']['supplier_id'] ?? $inputFields['supplier_id'] ?? null)
            : null;
        $fields = [
            'number' => $inputFields['number'] ?? $proposal?->number ?? $tender?->number,
            'date' => $inputFields['date'] ?? now()->toDateString(),
            'subject' => $inputFields['subject'] ?? trans_message('crm.conversion.defaults.contract_subject', [
                'title' => (string) ($proposal?->title ?? $tender?->title ?? $deal->title),
            ]),
            'status' => $inputFields['status'] ?? 'draft',
            'contract_side_type' => $sideType,
            'base_amount' => $baseAmount,
            'total_amount' => $inputFields['total_amount'] ?? $baseAmount,
            'start_date' => $inputFields['start_date'] ?? null,
            'end_date' => $inputFields['end_date'] ?? null,
            'notes' => $inputFields['notes'] ?? null,
            'is_fixed_amount' => $isFixedAmount,
            'contractor_id' => $data['counterparty']['contractor_id'] ?? $counterparty['contractor_id'],
            'supplier_id' => $supplierId,
        ];
        $required = $mode === 'create'
            ? ['number', 'date', 'status', 'contract_side_type']
            : ['contract_id'];

        if ($mode === 'create' && in_array($sideType, self::CONTRACTOR_SIDE_TYPES, true)) {
            $required[] = 'contractor_id';
        }

        if ($mode === 'create' && in_array($sideType, self::SUPPLIER_SIDE_TYPES, true)) {
            $required[] = 'supplier_id';
        }

        if ($mode === 'create' && $isFixedAmount) {
            $required[] = 'base_amount';
        }

        $missing = [];

        if ($mode === 'reuse' && ! $existing) {
            $missing[] = 'contract_id';
        }

        if ($mode === 'create') {
            foreach ($required as $field) {
                if ($this->blank($fields[$field] ?? null)) {
                    $missing[] = $field;
                }
            }
        }

        return [
            'mode' => $mode,
            'existing' => $existing,
            'fields' => $fields,
            'counterparty' => $counterparty,
            'required_fields' => $required,
            'missing_fields' => array_values(array_unique($missing)),
        ];
    }

    private function resolveAmount(int $organizationId, CrmDeal $deal, ?Tender $tender, ?CommercialProposal $proposal, User $user): array
    {
        if ($proposal instanceof CommercialProposal) {
            $visible = $this->can($user, 'commercial_proposals.amounts.view', $organizationId);

            return [
                'amount_visible' => $visible,
                'value' => $visible ? $this->decimalString($this->commercialProposalAmount($proposal)) : null,
                'currency' => $proposal->currency ?? 'RUB',
                'source' => 'commercial_proposal',
            ];
        }

        if ($tender instanceof Tender) {
            $visible = $this->can($user, 'tenders.amounts.view', $organizationId);

            return [
                'amount_visible' => $visible,
                'value' => $visible ? $this->decimalString($this->tenderAmount($tender)) : null,
                'currency' => $tender->currency ?? 'RUB',
                'source' => 'tender',
            ];
        }

        $visible = $this->can($user, 'crm.amounts.view', $organizationId);

        return [
            'amount_visible' => $visible,
            'value' => $visible ? $this->decimalString($deal->amount) : null,
            'currency' => $deal->currency ?? 'RUB',
            'source' => 'crm_deal',
        ];
    }

    private function commercialProposalAmount(CommercialProposal $proposal): mixed
    {
        $version = $proposal->acceptedVersion ?? $proposal->currentVersion;
        $totals = is_array($version?->totals_snapshot) ? $version->totals_snapshot : [];

        return $totals['total_amount']
            ?? $totals['total']
            ?? $totals['grand_total']
            ?? $proposal->total_amount;
    }

    private function tenderAmount(Tender $tender): mixed
    {
        return $tender->winner_amount
            ?? $tender->final_bid_amount
            ?? $tender->expected_bid_amount
            ?? $tender->initial_max_price;
    }

    private function resolveCounterparty(int $organizationId, CrmDeal $deal, array $data): array
    {
        $manualContractorId = $data['counterparty']['contractor_id'] ?? null;

        if ($manualContractorId) {
            $contractorId = (int) $manualContractorId;
            $available = $this->contractorSharing->canUseContractor($contractorId, $organizationId);
            $contractor = $available ? Contractor::query()->find($contractorId) : null;

            return [
                'contractor_id' => $available ? $contractorId : null,
                'contractor_name' => $contractor?->name,
                'source' => $available ? 'manual' : 'missing',
                'required' => true,
            ];
        }

        if ($deal->company?->linked_contractor_id) {
            $contractorId = (int) $deal->company->linked_contractor_id;

            if (! $this->contractorSharing->canUseContractor($contractorId, $organizationId)) {
                return [
                    'contractor_id' => null,
                    'contractor_name' => null,
                    'source' => 'missing',
                    'required' => true,
                ];
            }

            return [
                'contractor_id' => $contractorId,
                'contractor_name' => $deal->company->linkedContractor?->name,
                'source' => 'linked_company',
                'required' => true,
            ];
        }

        if ($deal->company?->inn) {
            $contractor = Contractor::query()
                ->where('organization_id', $organizationId)
                ->where('inn', $deal->company->inn)
                ->first();

            if ($contractor instanceof Contractor) {
                return [
                    'contractor_id' => $contractor->id,
                    'contractor_name' => $contractor->name,
                    'source' => 'inn_match',
                    'required' => true,
                ];
            }
        }

        return [
            'contractor_id' => null,
            'contractor_name' => null,
            'source' => 'missing',
            'required' => true,
        ];
    }

    private function resolveSupplierId(int $organizationId, mixed $supplierId): ?int
    {
        if ($this->blank($supplierId)) {
            return null;
        }

        $resolvedId = (int) $supplierId;

        $exists = Supplier::query()
            ->whereKey($resolvedId)
            ->where('organization_id', $organizationId)
            ->exists();

        return $exists ? $resolvedId : null;
    }

    private function appendAuthorizationBlockers(int $organizationId, User $user, array $context, array &$blockers): void
    {
        $requirements = [
            ['crm.deals.link'],
            ['admin.projects.edit', 'projects.create'],
            ['admin.contracts.edit', 'contracts.create'],
        ];

        if ($context['tender'] instanceof Tender) {
            $requirements[] = ['tenders.update'];
        }

        if ($context['commercial_proposal'] instanceof CommercialProposal) {
            $requirements[] = ['commercial_proposals.update'];
        }

        foreach ($requirements as $permissionGroup) {
            if (! $this->canAny($user, $permissionGroup, $organizationId)) {
                $blockers[] = [
                    'key' => 'permission_denied',
                    'label' => trans_message('crm.conversion.blockers.permission_denied'),
                ];

                return;
            }
        }
    }

    private function appendSourceBlockers(array $context, array &$blockers, array &$warnings): void
    {
        $deal = $context['deal'];
        $tender = $context['tender'];
        $proposal = $context['commercial_proposal'];

        if ($tender instanceof Tender && $tender->crm_deal_id !== null && $tender->crm_deal_id !== $deal->id) {
            $blockers[] = [
                'key' => 'tender_deal_mismatch',
                'label' => trans_message('crm.conversion.blockers.tender_deal_mismatch'),
            ];
        }

        if ($proposal instanceof CommercialProposal && $proposal->crm_deal_id !== null && $proposal->crm_deal_id !== $deal->id) {
            $blockers[] = [
                'key' => 'commercial_proposal_deal_mismatch',
                'label' => trans_message('crm.conversion.blockers.commercial_proposal_deal_mismatch'),
            ];
        }

        if (
            $tender instanceof Tender
            && $proposal instanceof CommercialProposal
            && $tender->commercial_proposal_id !== null
            && $tender->commercial_proposal_id !== $proposal->id
        ) {
            $warnings[] = [
                'key' => 'source_chain_incomplete',
                'label' => trans_message('crm.conversion.warnings.source_chain_incomplete'),
            ];
        }
    }

    private function appendLinkBlockers(array $context, array $projectPreview, array $contractPreview, array &$blockers): void
    {
        $projectIds = array_values(array_unique(array_filter([
            $context['deal']->project_id,
            $context['tender']?->project_id,
            $context['commercial_proposal']?->project_id,
        ])));
        $contractIds = array_values(array_unique(array_filter([
            $context['deal']->contract_id,
            $context['tender']?->contract_id,
            $context['commercial_proposal']?->contract_id,
        ])));

        if (count($projectIds) > 1) {
            $blockers[] = [
                'key' => 'project_link_conflict',
                'label' => trans_message('crm.conversion.blockers.project_link_conflict'),
            ];
        }

        if (count($contractIds) > 1) {
            $blockers[] = [
                'key' => 'contract_link_conflict',
                'label' => trans_message('crm.conversion.blockers.contract_link_conflict'),
            ];
        }

        if ($projectPreview['mode'] === 'reuse' && ! $projectPreview['existing']) {
            $blockers[] = [
                'key' => 'project_not_found',
                'label' => trans_message('crm.conversion.blockers.project_not_found'),
            ];
        }

        if ($contractPreview['mode'] === 'reuse' && ! $contractPreview['existing']) {
            $blockers[] = [
                'key' => 'contract_not_found',
                'label' => trans_message('crm.conversion.blockers.contract_not_found'),
            ];
        }
    }

    private function appendRequiredFieldBlockers(array $projectMissing, array $contractMissing, array &$blockers): void
    {
        foreach ($projectMissing as $field) {
            $blockers[] = [
                'key' => 'project_'.$field.'_required',
                'label' => trans_message("crm.conversion.missing.project.{$field}"),
                'field' => "project.{$field}",
            ];
        }

        foreach ($contractMissing as $field) {
            $blockers[] = [
                'key' => 'contract_'.$field.'_required',
                'label' => trans_message("crm.conversion.missing.contract.{$field}"),
                'field' => "contract.{$field}",
            ];
        }
    }

    private function resolveTender(int $organizationId, CrmDeal $deal, array $data, bool $lock): ?Tender
    {
        $query = Tender::query()
            ->forOrganization($organizationId)
            ->with(['files'])
            ->when($lock, static fn ($builder) => $builder->lockForUpdate());

        if (! empty($data['tender_id'])) {
            return $query->whereKey($data['tender_id'])->firstOrFail();
        }

        return (clone $query)
            ->where('crm_deal_id', $deal->id)
            ->orderByDesc('updated_at')
            ->first();
    }

    private function resolveCommercialProposal(int $organizationId, CrmDeal $deal, ?Tender $tender, array $data, bool $lock): ?CommercialProposal
    {
        $query = CommercialProposal::query()
            ->forOrganization($organizationId)
            ->with(['acceptedVersion', 'currentVersion', 'files'])
            ->when($lock, static fn ($builder) => $builder->lockForUpdate());

        if (! empty($data['commercial_proposal_id'])) {
            return $query->whereKey($data['commercial_proposal_id'])->firstOrFail();
        }

        if ($tender instanceof Tender && $tender->commercial_proposal_id) {
            $proposal = (clone $query)->whereKey($tender->commercial_proposal_id)->first();

            if ($proposal instanceof CommercialProposal) {
                return $proposal;
            }
        }

        return (clone $query)
            ->where(function ($builder) use ($deal, $tender): void {
                $builder->where('crm_deal_id', $deal->id);

                if ($tender instanceof Tender) {
                    $builder->orWhere('tender_id', $tender->id);
                }
            })
            ->orderByDesc('updated_at')
            ->first();
    }

    private function tenderCandidates(int $organizationId, string $dealId): array
    {
        return Tender::query()
            ->forOrganization($organizationId)
            ->where('crm_deal_id', $dealId)
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get(['id', 'number', 'title', 'status'])
            ->map(fn (Tender $tender): array => $this->tenderSummary($tender))
            ->values()
            ->all();
    }

    private function commercialProposalCandidates(int $organizationId, string $dealId, ?string $tenderId): array
    {
        return CommercialProposal::query()
            ->forOrganization($organizationId)
            ->where(function ($builder) use ($dealId, $tenderId): void {
                $builder->where('crm_deal_id', $dealId);

                if ($tenderId !== null) {
                    $builder->orWhere('tender_id', $tenderId);
                }
            })
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get(['id', 'number', 'title', 'status'])
            ->map(fn (CommercialProposal $proposal): array => $this->commercialProposalSummary($proposal))
            ->values()
            ->all();
    }

    private function documentReferences(?Tender $tender, ?CommercialProposal $proposal): array
    {
        $documents = [];

        if ($tender instanceof Tender && $tender->relationLoaded('files')) {
            foreach ($tender->files as $file) {
                $documents[] = [
                    'source' => 'tender',
                    'id' => $file->id,
                    'name' => $file->original_name ?? $file->name ?? trans_message('crm.conversion.documents.file'),
                    'storage_path' => $file->stored_path ?? null,
                ];
            }
        }

        if ($proposal instanceof CommercialProposal && $proposal->relationLoaded('files')) {
            foreach ($proposal->files as $file) {
                $documents[] = [
                    'source' => 'commercial_proposal',
                    'id' => $file->id,
                    'name' => $file->original_name ?? $file->filename ?? trans_message('crm.conversion.documents.file'),
                    'storage_path' => $file->storage_path ?? null,
                ];
            }
        }

        return $documents;
    }

    private function budgetSeed(array $data, array $amount, ?CommercialProposal $proposal, ?Tender $tender): array
    {
        return [
            'accepted' => (bool) ($data['budget_seed']['accepted'] ?? false),
            'amount_visible' => $amount['amount_visible'],
            'source' => $amount['source'],
            'amount' => $amount['amount_visible'] ? $amount['value'] : null,
            'items' => [],
            'note' => trans_message('crm.conversion.budget_seed.note'),
            'source_id' => $proposal?->id ?? $tender?->id,
        ];
    }

    private function findProjectSummary(int $organizationId, int $projectId): ?array
    {
        $project = Project::query()
            ->whereKey($projectId)
            ->where('organization_id', $organizationId)
            ->first();

        return $project instanceof Project ? $this->projectSummary($project) : null;
    }

    private function findContractSummary(int $organizationId, int $contractId): ?array
    {
        $contract = Contract::query()
            ->whereKey($contractId)
            ->where('organization_id', $organizationId)
            ->first();

        return $contract instanceof Contract ? $this->contractSummary($contract) : null;
    }

    private function dealSummary(CrmDeal $deal): array
    {
        return [
            'id' => $deal->id,
            'title' => $deal->title,
            'status' => $deal->status,
            'company' => $deal->company ? [
                'id' => $deal->company->id,
                'name' => $deal->company->name,
                'linked_contractor_id' => $deal->company->linked_contractor_id,
            ] : null,
            'project_id' => $deal->project_id,
            'contract_id' => $deal->contract_id,
        ];
    }

    private function tenderSummary(?Tender $tender): ?array
    {
        if (! $tender instanceof Tender) {
            return null;
        }

        return [
            'id' => $tender->id,
            'number' => $tender->number,
            'title' => $tender->title,
            'status' => $tender->status,
            'project_id' => $tender->project_id,
            'contract_id' => $tender->contract_id,
        ];
    }

    private function commercialProposalSummary(?CommercialProposal $proposal): ?array
    {
        if (! $proposal instanceof CommercialProposal) {
            return null;
        }

        return [
            'id' => $proposal->id,
            'number' => $proposal->number,
            'title' => $proposal->title,
            'status' => $this->enumValue($proposal->status),
            'project_id' => $proposal->project_id,
            'contract_id' => $proposal->contract_id,
        ];
    }

    private function projectSummary(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'status' => $project->status,
            'path' => "/projects/{$project->id}",
        ];
    }

    private function contractSummary(Contract $contract): array
    {
        return [
            'id' => $contract->id,
            'number' => $contract->number,
            'status' => $this->enumValue($contract->status),
            'project_id' => $contract->project_id,
            'path' => $contract->project_id ? "/projects/{$contract->project_id}/contracts/{$contract->id}" : "/contracts/{$contract->id}",
        ];
    }

    private function can(User $user, string $permission, int $organizationId): bool
    {
        return $this->authorization->can($user, $permission, ['organization_id' => $organizationId]);
    }

    private function canAny(User $user, array $permissions, int $organizationId): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($user, $permission, $organizationId)) {
                return true;
            }
        }

        return false;
    }

    private function payloadHash(array $data): string
    {
        unset($data['idempotency_key']);
        $this->ksortRecursive($data);

        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function previewHash(array $preview): string
    {
        unset($preview['preview_hash'], $preview['ready_to_convert']);
        $this->ksortRecursive($preview);

        return hash('sha256', json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function ksortRecursive(array &$value): void
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->ksortRecursive($item);
            }
        }

        ksort($value);
    }

    private function blank(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function decimalString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof BackedEnum ? (string) $value->value : (string) $value;
    }
}
