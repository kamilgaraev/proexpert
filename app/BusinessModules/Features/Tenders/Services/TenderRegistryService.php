<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Tenders\Services;

use App\BusinessModules\Features\Crm\Models\CrmCompany;
use App\BusinessModules\Features\Crm\Models\CrmContact;
use App\BusinessModules\Features\Crm\Models\CrmDeal;
use App\BusinessModules\Features\Tenders\Models\Tender;
use App\BusinessModules\Features\Tenders\Models\TenderDeadline;
use App\BusinessModules\Features\Tenders\Models\TenderSource;
use App\Models\Contract;
use App\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function trans_message;

final class TenderRegistryService
{
    private const TERMINAL_STATUSES = ['won', 'lost', 'cancelled'];

    private const STATUS_VALUES = [
        'incoming',
        'analysis',
        'go_no_go',
        'preparation',
        'submitted',
        'auction_waiting',
        'won',
        'lost',
        'cancelled',
    ];

    private const PRIORITY_VALUES = ['low', 'normal', 'high', 'urgent'];

    private const RISK_VALUES = ['low', 'medium', 'high', 'critical'];

    private const DEADLINE_KINDS = [
        'publication',
        'questions',
        'submission',
        'opening',
        'auction',
        'result',
        'contract_signing',
        'custom',
    ];

    public function __construct(
        private readonly TenderDeadlineService $deadlines,
        private readonly TenderTimelineService $timeline
    ) {
    }

    public function summary(int $organizationId, bool $canViewAmounts): array
    {
        $base = Tender::query()->forOrganization($organizationId);
        $active = Tender::query()->forOrganization($organizationId)->whereNotIn('status', self::TERMINAL_STATUSES);

        return [
            'total' => (clone $base)->count(),
            'active' => (clone $active)->count(),
            'overdue' => (clone $active)->where('next_deadline_at', '<', now())->count(),
            'due_today' => (clone $active)->whereDate('next_deadline_at', now()->toDateString())->count(),
            'due_7_days' => (clone $active)->whereBetween('next_deadline_at', [now(), now()->addDays(7)])->count(),
            'go_no_go_required' => (clone $base)->where('status', 'go_no_go')->count(),
            'preparation' => (clone $base)->where('status', 'preparation')->count(),
            'won' => (clone $base)->where('status', 'won')->count(),
            'lost' => (clone $base)->where('status', 'lost')->count(),
            'amount_visible' => $canViewAmounts,
        ];
    }

    public function references(int $organizationId): array
    {
        $sources = TenderSource::query()
            ->where(function (Builder $query) use ($organizationId): void {
                $query->where('organization_id', $organizationId)->orWhereNull('organization_id');
            })
            ->where('is_active', true)
            ->orderBy('label')
            ->get(['id', 'code', 'label', 'source_type', 'base_url']);

        return [
            'sources' => $sources->map(fn (TenderSource $source): array => [
                'id' => $source->id,
                'code' => $source->code,
                'label' => $source->label,
                'source_type' => $source->source_type,
                'base_url' => $source->base_url,
            ])->values()->all(),
            'statuses' => $this->options('statuses', self::STATUS_VALUES),
            'priorities' => $this->options('priorities', self::PRIORITY_VALUES),
            'risk_levels' => $this->options('risk_levels', self::RISK_VALUES),
            'deadline_kinds' => $this->options('deadline_kinds', self::DEADLINE_KINDS),
        ];
    }

    public function paginate(int $organizationId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Tender::query()
            ->forOrganization($organizationId)
            ->with($this->listRelations());

        $this->applyArchiveFilter($query, $filters);

        if (!empty($filters['q'])) {
            $search = '%' . (string) $filters['q'] . '%';
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('title', 'like', $search)
                    ->orWhere('number', 'like', $search)
                    ->orWhere('external_number', 'like', $search)
                    ->orWhere('customer_name', 'like', $search)
                    ->orWhere('customer_inn', 'like', $search);
            });
        }

        foreach (['status', 'risk_level', 'priority', 'source_id', 'owner_user_id', 'go_no_go_decision'] as $field) {
            if (array_key_exists($field, $filters) && $filters[$field] !== null && $filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }

        if (!empty($filters['overdue'])) {
            $query->whereNotIn('status', self::TERMINAL_STATUSES)->where('next_deadline_at', '<', now());
        }

        if (($filters['deadline'] ?? null) === 'today') {
            $query->whereDate('next_deadline_at', now()->toDateString());
        }

        if (($filters['deadline'] ?? null) === 'week') {
            $query->whereBetween('next_deadline_at', [now(), now()->addDays(7)]);
        }

        return $query
            ->orderBy($this->sortBy($filters), $this->sortDir($filters))
            ->paginate($perPage);
    }

    public function find(int $organizationId, string $id, bool $withTrashed = true): Tender
    {
        $query = Tender::query()->forOrganization($organizationId)->with($this->detailRelations());

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->findOrFail($id);
    }

    public function create(int $organizationId, array $data, ?int $actorUserId): Tender
    {
        return DB::transaction(function () use ($organizationId, $data, $actorUserId): Tender {
            $this->validateReferences($organizationId, $data);
            $tender = Tender::query()->create($this->attributes($organizationId, $data, $actorUserId, true));
            $this->syncSystemDeadlines($tender);
            $this->timeline->record($organizationId, $tender->id, 'created', trans_message('tenders.timeline.created'), $actorUserId);

            return $this->find($organizationId, $tender->id);
        });
    }

    public function update(int $organizationId, string $id, array $data, ?int $actorUserId): Tender
    {
        return DB::transaction(function () use ($organizationId, $id, $data, $actorUserId): Tender {
            if (array_key_exists('status', $data)) {
                throw ValidationException::withMessages([
                    'status' => trans_message('tenders.validation.status_direct_update'),
                ]);
            }

            $tender = $this->find($organizationId, $id);
            $this->validateReferences($organizationId, $data, $tender);
            $tender->update($this->attributes($organizationId, $data, $actorUserId, false));
            $this->syncSystemDeadlines($tender->refresh());
            $this->timeline->record($organizationId, $tender->id, 'updated', trans_message('tenders.timeline.updated'), $actorUserId);

            return $this->find($organizationId, $tender->id);
        });
    }

    public function archive(int $organizationId, string $id, ?int $actorUserId): Tender
    {
        return DB::transaction(function () use ($organizationId, $id, $actorUserId): Tender {
            $tender = $this->find($organizationId, $id);
            $tender->delete();
            $this->timeline->record($organizationId, $id, 'archived', trans_message('tenders.timeline.archived'), $actorUserId);

            return $this->find($organizationId, $id);
        });
    }

    public function restore(int $organizationId, string $id, ?int $actorUserId): Tender
    {
        return DB::transaction(function () use ($organizationId, $id, $actorUserId): Tender {
            $tender = $this->find($organizationId, $id);
            $tender->restore();
            $this->timeline->record($organizationId, $id, 'restored', trans_message('tenders.timeline.restored'), $actorUserId);

            return $this->find($organizationId, $id);
        });
    }

    public function serialize(Tender $tender, bool $canViewAmounts, bool $detail = false): array
    {
        $nextDeadline = $this->deadlines->resolveNextDeadline($tender->relationLoaded('deadlines') ? $tender->deadlines : []);

        return [
            'id' => $tender->id,
            'organization_id' => $tender->organization_id,
            'source_id' => $tender->source_id,
            'customer_company_id' => $tender->customer_company_id,
            'customer_contact_id' => $tender->customer_contact_id,
            'owner_user_id' => $tender->owner_user_id,
            'crm_deal_id' => $tender->crm_deal_id,
            'commercial_proposal_id' => $tender->commercial_proposal_id,
            'project_id' => $tender->project_id,
            'contract_id' => $tender->contract_id,
            'number' => $tender->number,
            'external_number' => $tender->external_number,
            'external_url' => $tender->external_url,
            'title' => $tender->title,
            'description' => $tender->description,
            'customer' => $this->customer($tender),
            'source' => $this->source($tender),
            'owner' => $this->userSummary($tender->relationLoaded('owner') ? $tender->owner : null),
            'status' => $tender->status,
            'status_label' => $this->label('statuses', $tender->status),
            'priority' => $tender->priority,
            'priority_label' => $this->label('priorities', $tender->priority),
            'risk_level' => $tender->risk_level,
            'risk_label' => $this->label('risk_levels', $tender->risk_level),
            'initial_max_price' => $this->amount($tender->initial_max_price, $canViewAmounts),
            'budget_missing_reason' => $tender->budget_missing_reason,
            'expected_bid_amount' => $this->amount($tender->expected_bid_amount, $canViewAmounts),
            'final_bid_amount' => $this->amount($tender->final_bid_amount, $canViewAmounts),
            'final_bid_amount_missing_reason' => $tender->final_bid_amount_missing_reason,
            'winner_amount' => $this->amount($tender->winner_amount, $canViewAmounts),
            'currency' => $tender->currency,
            'amount_visible' => $canViewAmounts,
            'published_at' => $this->date($tender->published_at),
            'questions_deadline_at' => $this->date($tender->questions_deadline_at),
            'submission_deadline_at' => $this->date($tender->submission_deadline_at),
            'submitted_at' => $this->date($tender->submitted_at),
            'opening_at' => $this->date($tender->opening_at),
            'auction_at' => $this->date($tender->auction_at),
            'result_expected_at' => $this->date($tender->result_expected_at),
            'result_published_at' => $this->date($tender->result_published_at),
            'next_deadline_at' => $this->date($tender->next_deadline_at),
            'next_deadline' => $nextDeadline === null ? null : $this->deadline($nextDeadline, $tender->status),
            'is_overdue' => $this->deadlines->isOverdue($tender->next_deadline_at, null, $tender->status),
            'go_no_go_decision' => $tender->go_no_go_decision,
            'go_no_go_reason' => $tender->go_no_go_reason,
            'lost_reason' => $tender->lost_reason,
            'cancel_reason' => $tender->cancel_reason,
            'winner_name' => $tender->winner_name,
            'requirements_summary' => $tender->requirements_summary,
            'analysis_summary' => $tender->analysis_summary,
            'requirements_payload' => $tender->requirements ?? [],
            'evaluation_criteria' => $tender->evaluation_criteria ?? [],
            'metadata' => $tender->metadata ?? [],
            'workflow_summary' => $this->workflowSummary($tender, $nextDeadline, $canViewAmounts),
            'links' => $this->links($tender),
            'requirements' => $detail ? $this->requirements($tender) : [],
            'files' => $detail ? $this->files($tender) : [],
            'risks' => $detail ? $this->risks($tender) : [],
            'competitors' => $detail ? $this->competitors($tender, $canViewAmounts) : [],
            'deadlines' => $detail ? $this->deadlineList($tender) : [],
            'timeline' => $detail ? $this->timelineList($tender) : [],
            'is_archived' => $tender->deleted_at !== null,
            'created_at' => $this->date($tender->created_at),
            'updated_at' => $this->date($tender->updated_at),
            'deleted_at' => $this->date($tender->deleted_at),
        ];
    }

    public function refreshNextDeadline(Tender $tender): void
    {
        $next = $this->deadlines->resolveNextDeadline($tender->deadlines()->get());

        $tender->forceFill([
            'next_deadline_at' => $next['due_at'] ?? null,
        ])->save();
    }

    private function syncSystemDeadlines(Tender $tender): void
    {
        $definitions = [
            'publication' => ['field' => 'published_at', 'title' => trans_message('tenders.deadlines.publication')],
            'questions' => ['field' => 'questions_deadline_at', 'title' => trans_message('tenders.deadlines.questions')],
            'submission' => ['field' => 'submission_deadline_at', 'title' => trans_message('tenders.deadlines.submission')],
            'opening' => ['field' => 'opening_at', 'title' => trans_message('tenders.deadlines.opening')],
            'auction' => ['field' => 'auction_at', 'title' => trans_message('tenders.deadlines.auction')],
            'result' => ['field' => 'result_expected_at', 'title' => trans_message('tenders.deadlines.result')],
        ];

        foreach ($definitions as $kind => $definition) {
            $dueAt = $tender->{$definition['field']};

            if ($dueAt === null) {
                continue;
            }

            TenderDeadline::query()->updateOrCreate(
                ['tender_id' => $tender->id, 'kind' => $kind],
                [
                    'title' => $definition['title'],
                    'due_at' => $dueAt,
                    'responsible_user_id' => $tender->owner_user_id,
                    'reminder_policy' => ['days_before' => [7, 3, 1], 'same_day' => true],
                    'is_required' => in_array($kind, ['submission'], true),
                    'metadata' => ['system' => true],
                ]
            );
        }

        $this->refreshNextDeadline($tender->refresh());
    }

    private function attributes(int $organizationId, array $data, ?int $actorUserId, bool $creating): array
    {
        $allowed = [
            'source_id',
            'customer_company_id',
            'customer_contact_id',
            'owner_user_id',
            'crm_deal_id',
            'commercial_proposal_id',
            'project_id',
            'contract_id',
            'external_number',
            'external_url',
            'title',
            'description',
            'customer_name',
            'customer_inn',
            'customer_kpp',
            'customer_ogrn',
            'priority',
            'risk_level',
            'initial_max_price',
            'budget_missing_reason',
            'expected_bid_amount',
            'currency',
            'published_at',
            'questions_deadline_at',
            'submission_deadline_at',
            'opening_at',
            'auction_at',
            'result_expected_at',
            'requirements_summary',
            'analysis_summary',
            'requirements',
            'evaluation_criteria',
            'metadata',
        ];
        $attributes = Arr::only($data, $allowed);
        $attributes['updated_by_user_id'] = $actorUserId;

        if ($creating) {
            $attributes['organization_id'] = $organizationId;
            $attributes['number'] = $data['number'] ?? $this->generateNumber($organizationId);
            $attributes['status'] = 'incoming';
            $attributes['priority'] = $attributes['priority'] ?? 'normal';
            $attributes['risk_level'] = $attributes['risk_level'] ?? 'medium';
            $attributes['currency'] = $attributes['currency'] ?? 'RUB';
            $attributes['go_no_go_decision'] = $attributes['go_no_go_decision'] ?? 'pending';
            $attributes['requirements'] = $attributes['requirements'] ?? [];
            $attributes['evaluation_criteria'] = $attributes['evaluation_criteria'] ?? [];
            $attributes['metadata'] = $attributes['metadata'] ?? [];
            $attributes['created_by_user_id'] = $actorUserId;
        }

        return $attributes;
    }

    private function validateReferences(int $organizationId, array $data, ?Tender $tender = null): void
    {
        if ($tender === null || array_key_exists('source_id', $data)) {
            $this->ensureSourceBelongsToOrganization($organizationId, $data['source_id'] ?? null);
        }

        if ($tender === null || array_key_exists('customer_company_id', $data)) {
            $this->ensureModelBelongsToOrganization(CrmCompany::class, 'customer_company_id', $organizationId, $data['customer_company_id'] ?? null);
        }

        if ($tender === null || array_key_exists('customer_contact_id', $data)) {
            $this->ensureModelBelongsToOrganization(CrmContact::class, 'customer_contact_id', $organizationId, $data['customer_contact_id'] ?? null);
        }

        if ($tender === null || array_key_exists('crm_deal_id', $data)) {
            $this->ensureModelBelongsToOrganization(CrmDeal::class, 'crm_deal_id', $organizationId, $data['crm_deal_id'] ?? null);
        }

        if ($tender === null || array_key_exists('project_id', $data)) {
            $this->ensureProjectBelongsToOrganization($organizationId, $data['project_id'] ?? null);
        }

        if ($tender === null || array_key_exists('contract_id', $data)) {
            $this->ensureContractBelongsToOrganization($organizationId, $data['contract_id'] ?? null);
        }

        if ($tender === null || array_key_exists('owner_user_id', $data)) {
            $this->ensureOwnerBelongsToOrganization($organizationId, $data['owner_user_id'] ?? null);
        }
    }

    private function ensureSourceBelongsToOrganization(int $organizationId, mixed $sourceId): void
    {
        if ($this->emptyReference($sourceId)) {
            return;
        }

        $exists = TenderSource::query()
            ->whereKey($sourceId)
            ->where(function (Builder $query) use ($organizationId): void {
                $query->where('organization_id', $organizationId)->orWhereNull('organization_id');
            })
            ->exists();

        if (! $exists) {
            $this->throwReferenceValidation('source_id', 'tenders.validation.source_invalid');
        }
    }

    private function ensureModelBelongsToOrganization(string $modelClass, string $field, int $organizationId, mixed $id): void
    {
        if ($this->emptyReference($id)) {
            return;
        }

        $exists = $modelClass::query()
            ->whereKey($id)
            ->where('organization_id', $organizationId)
            ->exists();

        if (! $exists) {
            $this->throwReferenceValidation($field, 'tenders.validation.reference_invalid');
        }
    }

    private function ensureOwnerBelongsToOrganization(int $organizationId, mixed $ownerUserId): void
    {
        if ($this->emptyReference($ownerUserId)) {
            return;
        }

        $exists = DB::table('organization_user')
            ->where('organization_id', $organizationId)
            ->where('user_id', $ownerUserId)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            $this->throwReferenceValidation('owner_user_id', 'tenders.validation.owner_invalid');
        }
    }

    private function ensureProjectBelongsToOrganization(int $organizationId, mixed $projectId): void
    {
        if ($this->emptyReference($projectId)) {
            return;
        }

        $exists = Project::query()
            ->whereKey($projectId)
            ->accessibleByOrganization($organizationId)
            ->exists();

        if (! $exists) {
            $this->throwReferenceValidation('project_id', 'tenders.validation.project_invalid');
        }
    }

    private function ensureContractBelongsToOrganization(int $organizationId, mixed $contractId): void
    {
        if ($this->emptyReference($contractId)) {
            return;
        }

        $exists = Contract::query()
            ->whereKey($contractId)
            ->where('organization_id', $organizationId)
            ->exists();

        if (! $exists) {
            $this->throwReferenceValidation('contract_id', 'tenders.validation.contract_invalid');
        }
    }

    private function customer(Tender $tender): array
    {
        $company = $tender->relationLoaded('customerCompany') ? $tender->customerCompany : null;

        return [
            'id' => $company?->id,
            'name' => $company?->name ?? $tender->customer_name,
            'inn' => $company?->inn ?? $tender->customer_inn,
            'kpp' => $company?->kpp ?? $tender->customer_kpp,
            'ogrn' => $company?->ogrn ?? $tender->customer_ogrn,
        ];
    }

    private function source(Tender $tender): ?array
    {
        $source = $tender->relationLoaded('source') ? $tender->source : null;

        if ($source === null) {
            return null;
        }

        return [
            'id' => $source->id,
            'code' => $source->code,
            'label' => $source->label,
            'source_type' => $source->source_type,
            'base_url' => $source->base_url,
        ];
    }

    private function links(Tender $tender): array
    {
        return [
            'crm_deal' => $tender->relationLoaded('crmDeal') && $tender->crmDeal !== null ? [
                'id' => $tender->crmDeal->id,
                'title' => $tender->crmDeal->title,
                'status' => $tender->crmDeal->status,
            ] : null,
            'commercial_proposal' => $tender->commercial_proposal_id === null ? null : [
                'id' => $tender->commercial_proposal_id,
                'title' => trans_message('tenders.links.commercial_proposal') . ' ' . $tender->commercial_proposal_id,
            ],
            'project' => $tender->relationLoaded('project') && $tender->project !== null ? [
                'id' => $tender->project->id,
                'name' => $tender->project->name,
                'status' => $tender->project->status ?? null,
            ] : null,
            'contract' => $tender->relationLoaded('contract') && $tender->contract !== null ? [
                'id' => $tender->contract->id,
                'number' => $tender->contract->number,
                'status' => $tender->contract->status ?? null,
            ] : null,
        ];
    }

    private function workflowSummary(Tender $tender, ?array $nextDeadline, bool $canViewAmounts): array
    {
        $problemFlags = $this->problemFlags($tender, $nextDeadline, $canViewAmounts);
        $availableActions = $this->availableActions($tender);

        return [
            'stage' => in_array($tender->status, self::TERMINAL_STATUSES, true) ? 'result' : 'participation',
            'stage_label' => in_array($tender->status, self::TERMINAL_STATUSES, true)
                ? trans_message('tenders.workflow.stage_result')
                : trans_message('tenders.workflow.stage_participation'),
            'status' => $tender->status,
            'status_label' => $this->label('statuses', $tender->status),
            'next_action' => $availableActions[1] ?? $availableActions[0] ?? null,
            'next_action_label' => isset($availableActions[1])
                ? $this->label('actions', $availableActions[1])
                : (isset($availableActions[0]) ? $this->label('actions', $availableActions[0]) : null),
            'available_actions' => $availableActions,
            'available_action_details' => array_map(fn (string $action): array => [
                'action' => $action,
                'label' => $this->label('actions', $action),
                'permission' => $this->actionPermission($action),
                'enabled' => true,
                'blockers' => [],
            ], $availableActions),
            'problem_flags' => $problemFlags,
            'blockers' => [],
            'warnings' => [],
            'meta' => [
                'overdue' => $this->deadlines->isOverdue($tender->next_deadline_at, null, $tender->status),
                'days_to_submission' => $this->deadlines->daysToDeadline($tender->submission_deadline_at),
            ],
        ];
    }

    private function problemFlags(Tender $tender, ?array $nextDeadline, bool $canViewAmounts): array
    {
        $flags = [];

        if ($tender->owner_user_id === null) {
            $flags[] = $this->flag('missing_owner', 'warning', 'owner');
        }

        if ($tender->customer_company_id === null && ($tender->customer_name === null || trim((string) $tender->customer_name) === '')) {
            $flags[] = $this->flag('missing_customer', 'warning', 'customer');
        }

        if ($tender->submission_deadline_at === null && ! in_array($tender->status, self::TERMINAL_STATUSES, true)) {
            $flags[] = $this->flag('missing_submission_deadline', 'warning', 'deadline');
        }

        if ($nextDeadline !== null && $this->deadlines->isOverdue($nextDeadline['due_at'] ?? null, $nextDeadline['completed_at'] ?? null, $tender->status)) {
            $flags[] = $this->flag('submission_deadline_overdue', 'critical', 'deadline', $nextDeadline['id'] ?? null);
        }

        $daysToSubmission = $this->deadlines->daysToDeadline($tender->submission_deadline_at);
        if ($daysToSubmission !== null && $daysToSubmission >= 0 && $daysToSubmission <= 3 && ! in_array($tender->status, self::TERMINAL_STATUSES, true)) {
            $flags[] = $this->flag('submission_deadline_near', 'warning', 'deadline', $nextDeadline['id'] ?? null);
        }

        if (! $canViewAmounts) {
            $flags[] = $this->flag('commercial_amount_hidden', 'info', 'amount');
        }

        return $flags;
    }

    private function flag(string $key, string $severity, string $target, ?string $targetId = null): array
    {
        return [
            'key' => $key,
            'label' => trans_message('tenders.problem_flags.' . $key),
            'severity' => $severity,
            'target' => $target,
            'target_id' => $targetId,
        ];
    }

    private function availableActions(Tender $tender): array
    {
        if (in_array($tender->status, self::TERMINAL_STATUSES, true)) {
            return ['update'];
        }

        return match ($tender->status) {
            'incoming' => ['update', 'analyze', 'cancel'],
            'analysis' => ['update', 'go_no_go', 'cancel'],
            'go_no_go' => ['update', 'go_no_go', 'cancel'],
            'preparation' => ['update', 'submit', 'cancel'],
            'submitted', 'auction_waiting' => ['update', 'result', 'cancel'],
            default => ['update', 'cancel'],
        };
    }

    private function actionPermission(string $action): ?string
    {
        return match ($action) {
            'update' => 'tenders.update',
            'analyze' => 'tenders.workflow.analyze',
            'go_no_go' => 'tenders.go_no_go.decide',
            'submit' => 'tenders.workflow.submit',
            'result' => 'tenders.workflow.result',
            'cancel' => 'tenders.workflow.cancel',
            default => null,
        };
    }

    private function deadlineList(Tender $tender): array
    {
        if (! $tender->relationLoaded('deadlines')) {
            return [];
        }

        return $tender->deadlines
            ->sortBy('due_at')
            ->map(fn ($deadline): array => $this->deadline($deadline, $tender->status))
            ->values()
            ->all();
    }

    private function deadline(mixed $deadline, string $tenderStatus): array
    {
        $dueAt = is_array($deadline) ? ($deadline['due_at'] ?? null) : $deadline->due_at;
        $completedAt = is_array($deadline) ? ($deadline['completed_at'] ?? null) : $deadline->completed_at;
        $kind = (string) (is_array($deadline) ? ($deadline['kind'] ?? 'custom') : $deadline->kind);

        return [
            'id' => is_array($deadline) ? ($deadline['id'] ?? null) : $deadline->id,
            'kind' => $kind,
            'kind_label' => $this->label('deadline_kinds', $kind),
            'title' => is_array($deadline) ? ($deadline['title'] ?? null) : $deadline->title,
            'due_at' => $this->date($dueAt),
            'completed_at' => $this->date($completedAt),
            'responsible_user' => is_array($deadline) ? null : $this->userSummary($deadline->relationLoaded('responsibleUser') ? $deadline->responsibleUser : null),
            'reminder_policy' => is_array($deadline) ? ($deadline['reminder_policy'] ?? []) : ($deadline->reminder_policy ?? []),
            'is_required' => (bool) (is_array($deadline) ? ($deadline['is_required'] ?? false) : $deadline->is_required),
            'is_overdue' => $this->deadlines->isOverdue($dueAt, $completedAt, $tenderStatus),
            'days_to_deadline' => $this->deadlines->daysToDeadline($dueAt),
        ];
    }

    private function requirements(Tender $tender): array
    {
        if (! $tender->relationLoaded('requirementsList')) {
            return [];
        }

        return $tender->requirementsList->map(fn ($item): array => [
            'id' => $item->id,
            'kind' => $item->kind,
            'title' => $item->title,
            'description' => $item->description,
            'is_required' => (bool) $item->is_required,
            'required_for_status' => $item->required_for_status,
            'status' => $item->status,
            'owner' => $this->userSummary($item->relationLoaded('owner') ? $item->owner : null),
            'due_at' => $this->date($item->due_at),
            'completed_at' => $this->date($item->completed_at),
        ])->values()->all();
    }

    private function files(Tender $tender): array
    {
        if (! $tender->relationLoaded('files')) {
            return [];
        }

        return $tender->files->map(fn ($file): array => [
            'id' => $file->id,
            'category' => $file->category,
            'original_name' => $file->original_name,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'uploaded_by' => $this->userSummary($file->relationLoaded('uploadedBy') ? $file->uploadedBy : null),
            'uploaded_at' => $this->date($file->uploaded_at),
        ])->values()->all();
    }

    private function risks(Tender $tender): array
    {
        if (! $tender->relationLoaded('risks')) {
            return [];
        }

        return $tender->risks->map(fn ($risk): array => [
            'id' => $risk->id,
            'kind' => $risk->kind,
            'severity' => $risk->severity,
            'severity_label' => $this->label('risk_levels', $risk->severity),
            'title' => $risk->title,
            'description' => $risk->description,
            'mitigation' => $risk->mitigation,
            'owner' => $this->userSummary($risk->relationLoaded('owner') ? $risk->owner : null),
            'status' => $risk->status,
        ])->values()->all();
    }

    private function competitors(Tender $tender, bool $canViewAmounts): array
    {
        if (! $tender->relationLoaded('competitors')) {
            return [];
        }

        return $tender->competitors->map(fn ($competitor): array => [
            'id' => $competitor->id,
            'crm_company_id' => $competitor->crm_company_id,
            'name' => $competitor->name,
            'inn' => $competitor->inn,
            'kpp' => $competitor->kpp,
            'bid_amount' => $this->amount($competitor->bid_amount, $canViewAmounts),
            'score' => $competitor->score,
            'rank' => $competitor->rank,
            'is_winner' => (bool) $competitor->is_winner,
            'notes' => $competitor->notes,
        ])->values()->all();
    }

    private function timelineList(Tender $tender): array
    {
        if (! $tender->relationLoaded('timeline')) {
            return [];
        }

        return $tender->timeline
            ->sortByDesc('created_at')
            ->map(fn ($event): array => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'summary' => $event->summary,
                'actor' => $this->userSummary($event->relationLoaded('actor') ? $event->actor : null),
                'metadata' => $event->metadata ?? [],
                'created_at' => $this->date($event->created_at),
            ])
            ->values()
            ->all();
    }

    private function userSummary(mixed $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email ?? null,
        ];
    }

    private function amount(mixed $value, bool $visible): ?string
    {
        if (! $visible || $value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return method_exists($value, 'toJSON') ? $value->toJSON() : (string) $value;
    }

    private function label(string $group, string $value): string
    {
        return trans_message('tenders.' . $group . '.' . $value);
    }

    private function options(string $group, array $values): array
    {
        return array_map(fn (string $value): array => [
            'value' => $value,
            'label' => $this->label($group, $value),
        ], $values);
    }

    private function listRelations(): array
    {
        return [
            'source',
            'customerCompany',
            'customerContact',
            'owner',
            'crmDeal',
            'project',
            'contract',
            'deadlines.responsibleUser',
        ];
    }

    private function detailRelations(): array
    {
        return [
            ...$this->listRelations(),
            'requirementsList.owner',
            'files.uploadedBy',
            'risks.owner',
            'competitors.crmCompany',
            'timeline.actor',
        ];
    }

    private function applyArchiveFilter(Builder $query, array $filters): void
    {
        if (($filters['archived'] ?? null) === true || ($filters['archived'] ?? null) === 'true') {
            $query->onlyTrashed();
        } elseif (($filters['with_archived'] ?? null) === true || ($filters['with_archived'] ?? null) === 'true') {
            $query->withTrashed();
        }
    }

    private function sortBy(array $filters): string
    {
        $allowed = ['created_at', 'updated_at', 'next_deadline_at', 'submission_deadline_at', 'title', 'status', 'risk_level'];
        $requested = (string) ($filters['sort_by'] ?? 'next_deadline_at');

        return in_array($requested, $allowed, true) ? $requested : 'next_deadline_at';
    }

    private function sortDir(array $filters): string
    {
        return ($filters['sort_dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
    }

    private function generateNumber(int $organizationId): string
    {
        $sequence = Tender::withTrashed()->forOrganization($organizationId)->count() + 1;

        return sprintf('TD-%s-%04d', now()->format('Y'), $sequence);
    }

    private function emptyReference(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    private function throwReferenceValidation(string $field, string $translationKey): never
    {
        throw ValidationException::withMessages([
            $field => trans_message($translationKey),
        ]);
    }
}
