<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Services;

use App\BusinessModules\Features\Crm\Models\CrmActivity;
use App\BusinessModules\Features\Crm\Models\CrmCompany;
use App\BusinessModules\Features\Crm\Models\CrmContact;
use App\BusinessModules\Features\Crm\Models\CrmContactIdentity;
use App\BusinessModules\Features\Crm\Models\CrmContactPoint;
use App\BusinessModules\Features\Crm\Models\CrmDeal;
use App\BusinessModules\Features\Crm\Models\CrmLead;
use App\BusinessModules\Features\Crm\Models\CrmPipeline;
use App\BusinessModules\Features\Crm\Models\CrmPipelineStage;
use App\BusinessModules\Features\Crm\Models\CrmSource;
use App\Models\Contract;
use App\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function trans_message;

final class CrmRegistryService
{
    public function __construct(
        private readonly CrmTextNormalizer $normalizer,
        private readonly CrmTimelineService $timeline
    ) {
    }

    public function summary(int $organizationId): array
    {
        return [
            'companies' => [
                'total' => CrmCompany::query()->forOrganization($organizationId)->count(),
                'active' => CrmCompany::query()->forOrganization($organizationId)->where('status', 'active')->count(),
                'archived' => CrmCompany::onlyTrashed()->forOrganization($organizationId)->count(),
            ],
            'leads' => [
                'new' => CrmLead::query()->forOrganization($organizationId)->where('status', 'new')->count(),
                'in_work' => CrmLead::query()->forOrganization($organizationId)->whereIn('status', ['qualified', 'in_work'])->count(),
                'converted' => CrmLead::query()->forOrganization($organizationId)->where('status', 'converted')->count(),
            ],
            'deals' => [
                'open' => CrmDeal::query()->forOrganization($organizationId)->where('status', 'open')->count(),
                'won' => CrmDeal::query()->forOrganization($organizationId)->where('status', 'won')->count(),
                'lost' => CrmDeal::query()->forOrganization($organizationId)->where('status', 'lost')->count(),
            ],
            'activities' => [
                'planned' => CrmActivity::query()->forOrganization($organizationId)->where('status', 'planned')->count(),
                'overdue' => CrmActivity::query()
                    ->forOrganization($organizationId)
                    ->where('status', 'planned')
                    ->where('due_at', '<', now())
                    ->count(),
            ],
        ];
    }

    public function references(int $organizationId): array
    {
        $sources = CrmSource::query()
            ->where(function (Builder $query) use ($organizationId): void {
                $query->where('organization_id', $organizationId)->orWhereNull('organization_id');
            })
            ->where('is_active', true)
            ->orderBy('label')
            ->get(['id', 'organization_id', 'code', 'label', 'channel_type', 'is_active']);

        $pipelines = CrmPipeline::query()
            ->where(function (Builder $query) use ($organizationId): void {
                $query->where('organization_id', $organizationId)->orWhereNull('organization_id');
            })
            ->where('is_active', true)
            ->with(['stages' => fn ($query) => $query->orderBy('sort_order')])
            ->orderByDesc('is_default')
            ->orderBy('label')
            ->get();

        return [
            'sources' => $sources->map(fn (CrmSource $source): array => [
                'id' => $source->id,
                'code' => $source->code,
                'label' => $source->label,
                'channel_type' => $source->channel_type,
            ])->values()->all(),
            'pipelines' => $pipelines->map(fn (CrmPipeline $pipeline): array => [
                'id' => $pipeline->id,
                'code' => $pipeline->code,
                'label' => $pipeline->label,
                'entity_type' => $pipeline->entity_type,
                'is_default' => (bool) $pipeline->is_default,
                'stages' => $pipeline->stages->map(fn ($stage): array => [
                    'id' => $stage->id,
                    'code' => $stage->code,
                    'label' => $stage->label,
                    'category' => $stage->category,
                    'sort_order' => (int) $stage->sort_order,
                    'probability_percent' => $stage->probability_percent,
                    'is_terminal' => (bool) $stage->is_terminal,
                ])->values()->all(),
            ])->values()->all(),
            'defaults' => [
                'deal_pipeline' => [
                    'code' => 'default',
                    'label' => trans_message('crm.references.default_pipeline'),
                    'stages' => [
                        ['code' => 'new', 'label' => trans_message('crm.references.stage_new'), 'category' => 'open', 'probability_percent' => 10],
                        ['code' => 'qualification', 'label' => trans_message('crm.references.stage_qualification'), 'category' => 'open', 'probability_percent' => 30],
                        ['code' => 'proposal', 'label' => trans_message('crm.references.stage_proposal'), 'category' => 'open', 'probability_percent' => 60],
                        ['code' => 'contract', 'label' => trans_message('crm.references.stage_contract'), 'category' => 'open', 'probability_percent' => 80],
                        ['code' => 'won', 'label' => trans_message('crm.references.stage_won'), 'category' => 'won', 'probability_percent' => 100],
                        ['code' => 'lost', 'label' => trans_message('crm.references.stage_lost'), 'category' => 'lost', 'probability_percent' => 0],
                    ],
                ],
            ],
        ];
    }

    public function paginateCompanies(int $organizationId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = CrmCompany::query()
            ->forOrganization($organizationId)
            ->with(['owner', 'source', 'primaryContact']);

        $this->applyArchiveFilter($query, $filters);

        if (!empty($filters['merged'])) {
            $query->withTrashed()->whereNotNull('merged_into_id');
        } elseif (($filters['merged'] ?? null) === false) {
            $query->whereNull('merged_into_id');
        }

        if (!empty($filters['q'])) {
            $search = (string) $filters['q'];
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('name', 'ilike', '%' . $search . '%')
                    ->orWhere('legal_name', 'ilike', '%' . $search . '%')
                    ->orWhere('inn', 'ilike', '%' . $search . '%')
                    ->orWhere('phone', 'ilike', '%' . $search . '%')
                    ->orWhere('email', 'ilike', '%' . $search . '%');
            });
        }

        $this->applyCommonFilters($query, $filters, ['status', 'owner_user_id', 'source_id']);

        return $query
            ->orderBy($this->sortBy($filters, ['name', 'status', 'last_activity_at', 'created_at', 'updated_at']), $this->sortDir($filters))
            ->paginate($perPage);
    }

    public function paginateContacts(int $organizationId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = CrmContact::query()
            ->forOrganization($organizationId)
            ->with(['company', 'owner', 'source']);

        $this->applyArchiveFilter($query, $filters);

        if (!empty($filters['merged'])) {
            $query->withTrashed()->whereNotNull('merged_into_id');
        } elseif (($filters['merged'] ?? null) === false) {
            $query->whereNull('merged_into_id');
        }

        if (!empty($filters['q'])) {
            $search = (string) $filters['q'];
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('full_name', 'ilike', '%' . $search . '%')
                    ->orWhere('position', 'ilike', '%' . $search . '%')
                    ->orWhere('phone', 'ilike', '%' . $search . '%')
                    ->orWhere('email', 'ilike', '%' . $search . '%');
            });
        }

        $this->applyCommonFilters($query, $filters, ['status', 'owner_user_id', 'company_id', 'source_id']);

        return $query
            ->orderBy($this->sortBy($filters, ['full_name', 'status', 'last_activity_at', 'created_at', 'updated_at']), $this->sortDir($filters))
            ->paginate($perPage);
    }

    public function paginateLeads(int $organizationId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = CrmLead::query()
            ->forOrganization($organizationId)
            ->with(['company', 'contact', 'owner', 'source']);

        $this->applyArchiveFilter($query, $filters);

        if (!empty($filters['q'])) {
            $search = (string) $filters['q'];
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('title', 'ilike', '%' . $search . '%')
                    ->orWhere('need_description', 'ilike', '%' . $search . '%');
            });
        }

        $this->applyCommonFilters($query, $filters, ['status', 'owner_user_id', 'company_id', 'contact_id', 'source_id']);

        return $query
            ->orderBy($this->sortBy($filters, ['title', 'status', 'created_at', 'updated_at']), $this->sortDir($filters))
            ->paginate($perPage);
    }

    public function paginateDeals(int $organizationId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = CrmDeal::query()
            ->forOrganization($organizationId)
            ->with(['company', 'primaryContact', 'owner', 'pipeline', 'stage', 'source']);

        $this->applyArchiveFilter($query, $filters);

        if (!empty($filters['q'])) {
            $search = (string) $filters['q'];
            $query->where('title', 'ilike', '%' . $search . '%');
        }

        $this->applyCommonFilters($query, $filters, [
            'status',
            'owner_user_id',
            'company_id',
            'source_id',
            'pipeline_id',
            'stage_id',
            'pipeline_code',
            'stage_code',
        ]);

        return $query
            ->orderBy($this->sortBy($filters, ['title', 'status', 'expected_close_at', 'created_at', 'updated_at']), $this->sortDir($filters))
            ->paginate($perPage);
    }

    public function paginateActivities(int $organizationId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = CrmActivity::query()
            ->forOrganization($organizationId)
            ->with(['owner', 'company', 'contact', 'lead', 'deal']);

        $this->applyArchiveFilter($query, $filters);

        if (!empty($filters['q'])) {
            $search = (string) $filters['q'];
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('subject', 'ilike', '%' . $search . '%')
                    ->orWhere('body', 'ilike', '%' . $search . '%')
                    ->orWhere('result', 'ilike', '%' . $search . '%');
            });
        }

        $this->applyCommonFilters($query, $filters, ['status', 'owner_user_id', 'company_id', 'contact_id', 'lead_id', 'deal_id']);

        return $query
            ->orderBy($this->sortBy($filters, ['due_at', 'status', 'created_at', 'updated_at']), $this->sortDir($filters))
            ->paginate($perPage);
    }

    public function findCompany(int $organizationId, string $id, bool $withTrashed = true): CrmCompany
    {
        return $this->companyQuery($organizationId, $withTrashed)->findOrFail($id);
    }

    public function findContact(int $organizationId, string $id, bool $withTrashed = true): CrmContact
    {
        return $this->contactQuery($organizationId, $withTrashed)->findOrFail($id);
    }

    public function findLead(int $organizationId, string $id, bool $withTrashed = true): CrmLead
    {
        return $this->leadQuery($organizationId, $withTrashed)->findOrFail($id);
    }

    public function findDeal(int $organizationId, string $id, bool $withTrashed = true): CrmDeal
    {
        return $this->dealQuery($organizationId, $withTrashed)->findOrFail($id);
    }

    public function findActivity(int $organizationId, string $id, bool $withTrashed = true): CrmActivity
    {
        return $this->activityQuery($organizationId, $withTrashed)->findOrFail($id);
    }

    public function createCompany(int $organizationId, array $data, ?int $actorUserId): CrmCompany
    {
        return DB::transaction(function () use ($organizationId, $data, $actorUserId): CrmCompany {
            $this->validateCompanyReferences($organizationId, $data);
            $company = CrmCompany::query()->create($this->companyAttributes($organizationId, $data, $actorUserId));
            $this->syncContactDetails($organizationId, $company, null, $data);
            $this->timeline->record($organizationId, 'companies', $company->id, 'created', trans_message('crm.timeline.company_created'), $actorUserId);

            return $this->findCompany($organizationId, $company->id);
        });
    }

    public function updateCompany(int $organizationId, string $id, array $data, ?int $actorUserId): CrmCompany
    {
        return DB::transaction(function () use ($organizationId, $id, $data, $actorUserId): CrmCompany {
            $company = $this->findCompany($organizationId, $id);
            $this->validateCompanyReferences($organizationId, $data, false);
            $company->update($this->companyAttributes($organizationId, $data, $actorUserId, false));
            $this->syncContactDetails($organizationId, $company, null, $data);
            $this->timeline->record($organizationId, 'companies', $company->id, 'updated', trans_message('crm.timeline.company_updated'), $actorUserId);

            return $this->findCompany($organizationId, $company->id);
        });
    }

    public function createContact(int $organizationId, array $data, ?int $actorUserId): CrmContact
    {
        return DB::transaction(function () use ($organizationId, $data, $actorUserId): CrmContact {
            $this->validateContactReferences($organizationId, $data);
            $this->ensureSinglePrimaryContact($organizationId, $data);
            $contact = CrmContact::query()->create($this->contactAttributes($organizationId, $data, $actorUserId));
            $this->syncContactDetails($organizationId, null, $contact, $data);
            $this->timeline->record($organizationId, 'contacts', $contact->id, 'created', trans_message('crm.timeline.contact_created'), $actorUserId);

            return $this->findContact($organizationId, $contact->id);
        });
    }

    public function updateContact(int $organizationId, string $id, array $data, ?int $actorUserId): CrmContact
    {
        return DB::transaction(function () use ($organizationId, $id, $data, $actorUserId): CrmContact {
            $contact = $this->findContact($organizationId, $id);
            $this->validateContactReferences($organizationId, $data, false);
            $this->ensureSinglePrimaryContact($organizationId, $data, $contact->id);
            $contact->update($this->contactAttributes($organizationId, $data, $actorUserId, false));
            $this->syncContactDetails($organizationId, null, $contact, $data);
            $this->timeline->record($organizationId, 'contacts', $contact->id, 'updated', trans_message('crm.timeline.contact_updated'), $actorUserId);

            return $this->findContact($organizationId, $contact->id);
        });
    }

    public function createLead(int $organizationId, array $data, ?int $actorUserId): CrmLead
    {
        return DB::transaction(function () use ($organizationId, $data, $actorUserId): CrmLead {
            $this->validateLeadReferences($organizationId, $data);
            $lead = CrmLead::query()->create($this->leadAttributes($organizationId, $data, $actorUserId));
            $this->timeline->record($organizationId, 'leads', $lead->id, 'created', trans_message('crm.timeline.lead_created'), $actorUserId);

            return $this->findLead($organizationId, $lead->id);
        });
    }

    public function updateLead(int $organizationId, string $id, array $data, ?int $actorUserId): CrmLead
    {
        return DB::transaction(function () use ($organizationId, $id, $data, $actorUserId): CrmLead {
            $lead = $this->findLead($organizationId, $id);
            $this->validateLeadReferences($organizationId, $data, false);
            $lead->update($this->leadAttributes($organizationId, $data, $actorUserId, false));
            $this->timeline->record($organizationId, 'leads', $lead->id, 'updated', trans_message('crm.timeline.lead_updated'), $actorUserId);

            return $this->findLead($organizationId, $lead->id);
        });
    }

    public function createDeal(int $organizationId, array $data, ?int $actorUserId): CrmDeal
    {
        return DB::transaction(function () use ($organizationId, $data, $actorUserId): CrmDeal {
            $data = $this->validateDealReferences($organizationId, $data);
            $this->ensureCompanyBelongsToOrganization($organizationId, $data['company_id'] ?? null, true);
            $this->ensureContactBelongsToOrganization($organizationId, $data['primary_contact_id'] ?? null);
            $deal = CrmDeal::query()->create($this->dealAttributes($organizationId, $data, $actorUserId));
            $this->timeline->record($organizationId, 'deals', $deal->id, 'created', trans_message('crm.timeline.deal_created'), $actorUserId);

            return $this->findDeal($organizationId, $deal->id);
        });
    }

    public function updateDeal(int $organizationId, string $id, array $data, ?int $actorUserId): CrmDeal
    {
        return DB::transaction(function () use ($organizationId, $id, $data, $actorUserId): CrmDeal {
            $deal = $this->findDeal($organizationId, $id);
            $data = $this->validateDealReferences($organizationId, $data, $deal);
            if (array_key_exists('company_id', $data)) {
                $this->ensureCompanyBelongsToOrganization($organizationId, $data['company_id'] ?? null, true);
            }

            if (array_key_exists('primary_contact_id', $data)) {
                $this->ensureContactBelongsToOrganization($organizationId, $data['primary_contact_id'] ?? null);
            }

            $deal->update($this->dealAttributes($organizationId, $data, $actorUserId, false));
            $this->timeline->record($organizationId, 'deals', $deal->id, 'updated', trans_message('crm.timeline.deal_updated'), $actorUserId);

            return $this->findDeal($organizationId, $deal->id);
        });
    }

    public function createActivity(int $organizationId, array $data, ?int $actorUserId): CrmActivity
    {
        return DB::transaction(function () use ($organizationId, $data, $actorUserId): CrmActivity {
            $this->validateActivityReferences($organizationId, $data);
            $activity = CrmActivity::query()->create($this->activityAttributes($organizationId, $data, $actorUserId));
            $this->touchRelatedActivity($activity);
            $this->timeline->record($organizationId, 'activities', $activity->id, 'created', trans_message('crm.timeline.activity_created'), $actorUserId);

            return $this->findActivity($organizationId, $activity->id);
        });
    }

    public function updateActivity(int $organizationId, string $id, array $data, ?int $actorUserId): CrmActivity
    {
        return DB::transaction(function () use ($organizationId, $id, $data, $actorUserId): CrmActivity {
            $activity = $this->findActivity($organizationId, $id);
            $this->validateActivityReferences($organizationId, $data, false);
            $activity->update($this->activityAttributes($organizationId, $data, $actorUserId, false));
            $this->touchRelatedActivity($activity);
            $this->timeline->record($organizationId, 'activities', $activity->id, 'updated', trans_message('crm.timeline.activity_updated'), $actorUserId);

            return $this->findActivity($organizationId, $activity->id);
        });
    }

    public function archive(Model $model, ?int $actorUserId): Model
    {
        $model->update([
            'status' => 'archived',
            'updated_by_user_id' => $actorUserId,
        ]);
        $model->delete();

        return $model->refresh();
    }

    public function restore(Model $model, ?int $actorUserId): Model
    {
        if (method_exists($model, 'restore')) {
            $model->restore();
        }

        $status = match (true) {
            $model instanceof CrmDeal => 'open',
            $model instanceof CrmActivity => 'planned',
            $model instanceof CrmLead => 'new',
            default => 'active',
        };

        $model->update([
            'status' => $status,
            'updated_by_user_id' => $actorUserId,
        ]);

        return $model->refresh();
    }

    private function companyQuery(int $organizationId, bool $withTrashed): Builder
    {
        $query = CrmCompany::query()
            ->forOrganization($organizationId)
            ->with(['owner', 'source', 'primaryContact', 'contacts', 'contactPoints', 'identities', 'deals', 'leads', 'activities']);

        return $withTrashed ? $query->withTrashed() : $query;
    }

    private function contactQuery(int $organizationId, bool $withTrashed): Builder
    {
        $query = CrmContact::query()
            ->forOrganization($organizationId)
            ->with(['company', 'owner', 'source', 'contactPoints', 'identities']);

        return $withTrashed ? $query->withTrashed() : $query;
    }

    private function leadQuery(int $organizationId, bool $withTrashed): Builder
    {
        $query = CrmLead::query()
            ->forOrganization($organizationId)
            ->with(['company', 'contact', 'owner', 'source', 'activities']);

        return $withTrashed ? $query->withTrashed() : $query;
    }

    private function dealQuery(int $organizationId, bool $withTrashed): Builder
    {
        $query = CrmDeal::query()
            ->forOrganization($organizationId)
            ->with(['company', 'primaryContact', 'owner', 'pipeline', 'stage', 'source', 'activities']);

        return $withTrashed ? $query->withTrashed() : $query;
    }

    private function activityQuery(int $organizationId, bool $withTrashed): Builder
    {
        $query = CrmActivity::query()
            ->forOrganization($organizationId)
            ->with(['owner', 'company', 'contact', 'lead', 'deal']);

        return $withTrashed ? $query->withTrashed() : $query;
    }

    private function companyAttributes(int $organizationId, array $data, ?int $actorUserId, bool $creating = true): array
    {
        $attributes = Arr::only($data, [
            'owner_user_id',
            'linked_organization_id',
            'linked_contractor_id',
            'source_id',
            'source_ref_type',
            'source_ref_id',
            'name',
            'legal_name',
            'company_type',
            'roles',
            'status',
            'inn',
            'kpp',
            'ogrn',
            'phone',
            'email',
            'website',
            'legal_address',
            'actual_address',
            'tags',
            'custom_fields',
            'notes',
        ]);

        $this->normalizeEmptyReferences($attributes, ['owner_user_id', 'source_id']);
        $this->normalizeCompanyAttributes($attributes);
        $attributes['updated_by_user_id'] = $actorUserId;

        if ($creating) {
            $attributes['organization_id'] = $organizationId;
            $attributes['created_by_user_id'] = $actorUserId;
            $attributes['status'] = $attributes['status'] ?? 'new';
            $attributes['company_type'] = $attributes['company_type'] ?? 'legal_entity';
            $attributes['roles'] = $attributes['roles'] ?? [];
            $attributes['tags'] = $attributes['tags'] ?? [];
            $attributes['custom_fields'] = $attributes['custom_fields'] ?? [];
        }

        return $attributes;
    }

    private function contactAttributes(int $organizationId, array $data, ?int $actorUserId, bool $creating = true): array
    {
        $attributes = Arr::only($data, [
            'company_id',
            'owner_user_id',
            'source_id',
            'source_ref_type',
            'source_ref_id',
            'full_name',
            'position',
            'phone',
            'email',
            'messengers',
            'is_primary',
            'status',
            'personal_data_consent_at',
            'notes',
        ]);

        $this->normalizeEmptyReferences($attributes, ['company_id', 'owner_user_id', 'source_id']);
        $attributes['full_name'] = $this->normalizer->text($attributes['full_name'] ?? null) ?? ($attributes['full_name'] ?? null);
        $attributes['phone'] = $this->normalizer->phone($attributes['phone'] ?? null);
        $attributes['email'] = $this->normalizer->email($attributes['email'] ?? null);
        $attributes['updated_by_user_id'] = $actorUserId;

        if ($creating) {
            $attributes['organization_id'] = $organizationId;
            $attributes['created_by_user_id'] = $actorUserId;
            $attributes['status'] = $attributes['status'] ?? 'active';
            $attributes['messengers'] = $attributes['messengers'] ?? [];
            $attributes['is_primary'] = (bool) ($attributes['is_primary'] ?? false);
        }

        return $attributes;
    }

    private function leadAttributes(int $organizationId, array $data, ?int $actorUserId, bool $creating = true): array
    {
        $attributes = Arr::only($data, [
            'company_id',
            'contact_id',
            'owner_user_id',
            'source_id',
            'source_ref_type',
            'source_ref_id',
            'title',
            'status',
            'priority',
            'estimated_amount',
            'expected_start_date',
            'need_description',
            'utm',
            'raw_source_data',
            'lost_reason',
        ]);

        $this->normalizeEmptyReferences($attributes, ['company_id', 'contact_id', 'owner_user_id', 'source_id']);
        $attributes['updated_by_user_id'] = $actorUserId;

        if ($creating) {
            $attributes['organization_id'] = $organizationId;
            $attributes['created_by_user_id'] = $actorUserId;
            $attributes['status'] = $attributes['status'] ?? 'new';
            $attributes['priority'] = $attributes['priority'] ?? 'normal';
            $attributes['utm'] = $attributes['utm'] ?? [];
            $attributes['raw_source_data'] = $attributes['raw_source_data'] ?? [];
        }

        return $attributes;
    }

    private function dealAttributes(int $organizationId, array $data, ?int $actorUserId, bool $creating = true): array
    {
        $attributes = Arr::only($data, [
            'company_id',
            'primary_contact_id',
            'lead_id',
            'owner_user_id',
            'project_id',
            'contract_id',
            'pipeline_id',
            'stage_id',
            'source_id',
            'title',
            'pipeline_code',
            'stage_code',
            'status',
            'amount',
            'currency',
            'probability',
            'expected_close_at',
            'lost_reason',
            'next_activity_at',
            'custom_fields',
        ]);

        $this->normalizeEmptyReferences($attributes, [
            'primary_contact_id',
            'lead_id',
            'owner_user_id',
            'project_id',
            'contract_id',
            'pipeline_id',
            'stage_id',
            'source_id',
        ]);
        $attributes['updated_by_user_id'] = $actorUserId;

        if ($creating) {
            $attributes['organization_id'] = $organizationId;
            $attributes['created_by_user_id'] = $actorUserId;
            $attributes['pipeline_code'] = $attributes['pipeline_code'] ?? 'default';
            $attributes['stage_code'] = $attributes['stage_code'] ?? 'new';
            $attributes['status'] = $attributes['status'] ?? 'open';
            $attributes['currency'] = mb_strtoupper((string) ($attributes['currency'] ?? 'RUB'));
            $attributes['custom_fields'] = $attributes['custom_fields'] ?? [];
        } elseif (isset($attributes['currency'])) {
            $attributes['currency'] = mb_strtoupper((string) $attributes['currency']);
        }

        return $attributes;
    }

    private function activityAttributes(int $organizationId, array $data, ?int $actorUserId, bool $creating = true): array
    {
        $attributes = Arr::only($data, [
            'owner_user_id',
            'company_id',
            'contact_id',
            'lead_id',
            'deal_id',
            'type',
            'direction',
            'status',
            'subject',
            'body',
            'due_at',
            'completed_at',
            'result',
        ]);

        $this->normalizeEmptyReferences($attributes, ['owner_user_id', 'company_id', 'contact_id', 'lead_id', 'deal_id']);
        $attributes['updated_by_user_id'] = $actorUserId;

        if ($creating) {
            $attributes['organization_id'] = $organizationId;
            $attributes['created_by_user_id'] = $actorUserId;
            $attributes['status'] = $attributes['status'] ?? 'planned';
        }

        return $attributes;
    }

    private function normalizeCompanyAttributes(array &$attributes): void
    {
        if (array_key_exists('name', $attributes)) {
            $attributes['name'] = $this->normalizer->text($attributes['name']) ?? $attributes['name'];
        }

        if (array_key_exists('inn', $attributes)) {
            $attributes['inn'] = $this->normalizer->inn($attributes['inn']);
        }

        if (array_key_exists('phone', $attributes)) {
            $attributes['phone'] = $this->normalizer->phone($attributes['phone']);
        }

        if (array_key_exists('email', $attributes)) {
            $attributes['email'] = $this->normalizer->email($attributes['email']);
        }

        if (array_key_exists('website', $attributes)) {
            $attributes['website'] = $this->normalizer->domain($attributes['website']);
        }
    }

    private function normalizeEmptyReferences(array &$attributes, array $fields): void
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $attributes) && $attributes[$field] === '') {
                $attributes[$field] = null;
            }
        }
    }

    private function syncContactDetails(int $organizationId, ?CrmCompany $company, ?CrmContact $contact, array $data): void
    {
        if (array_key_exists('contact_points', $data)) {
            $query = CrmContactPoint::query();
            $company !== null
                ? $query->where('company_id', $company->id)->whereNull('contact_id')
                : $query->where('contact_id', $contact?->id);
            $query->delete();

            foreach ($data['contact_points'] ?? [] as $point) {
                CrmContactPoint::query()->create([
                    'organization_id' => $organizationId,
                    'company_id' => $company?->id,
                    'contact_id' => $contact?->id,
                    'point_type' => $point['point_type'],
                    'label' => $point['label'] ?? null,
                    'value' => $point['value'],
                    'normalized_value' => $this->normalizer->contactPoint($point['point_type'], $point['value']),
                    'is_primary' => (bool) ($point['is_primary'] ?? false),
                    'is_verified' => (bool) ($point['is_verified'] ?? false),
                    'metadata' => $point['metadata'] ?? [],
                ]);
            }
        }

        if (array_key_exists('identities', $data)) {
            $query = CrmContactIdentity::query();
            $company !== null
                ? $query->where('company_id', $company->id)->whereNull('contact_id')
                : $query->where('contact_id', $contact?->id);
            $query->delete();

            foreach ($data['identities'] ?? [] as $identity) {
                CrmContactIdentity::query()->create([
                    'organization_id' => $organizationId,
                    'company_id' => $company?->id,
                    'contact_id' => $contact?->id,
                    'identity_type' => $identity['identity_type'],
                    'value' => $identity['value'],
                    'normalized_value' => $this->normalizer->contactPoint($identity['identity_type'], $identity['value']),
                    'source' => $identity['source'] ?? null,
                    'metadata' => $identity['metadata'] ?? [],
                ]);
            }
        }
    }

    private function applyArchiveFilter(Builder $query, array $filters): void
    {
        if (($filters['archived'] ?? null) === true) {
            $query->onlyTrashed();
        } elseif (($filters['archived'] ?? null) === false) {
            $query->withoutTrashed();
        }
    }

    private function applyCommonFilters(Builder $query, array $filters, array $allowedFields): void
    {
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $filters) && $filters[$field] !== null && $filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }
    }

    private function sortBy(array $filters, array $allowed): string
    {
        $requested = (string) ($filters['sort_by'] ?? 'created_at');

        return in_array($requested, $allowed, true) ? $requested : 'created_at';
    }

    private function sortDir(array $filters): string
    {
        return ($filters['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
    }

    public function validateDealReferences(int $organizationId, array $data, ?CrmDeal $deal = null): array
    {
        if ($deal === null || array_key_exists('owner_user_id', $data)) {
            $this->ensureOwnerBelongsToOrganization($organizationId, $data['owner_user_id'] ?? null);
        }

        if ($deal === null || array_key_exists('project_id', $data)) {
            $this->ensureProjectBelongsToOrganization($organizationId, $data['project_id'] ?? null);
        }

        if ($deal === null || array_key_exists('contract_id', $data)) {
            $this->ensureContractBelongsToOrganization($organizationId, $data['contract_id'] ?? null);
        }

        if ($deal === null || array_key_exists('source_id', $data)) {
            $this->ensureSourceBelongsToOrganization($organizationId, $data['source_id'] ?? null);
        }

        if ($deal === null || array_key_exists('lead_id', $data)) {
            $this->ensureLeadBelongsToOrganization($organizationId, $data['lead_id'] ?? null);
        }

        return $this->normalizeDealPipelineReferences($organizationId, $data, $deal);
    }

    private function validateCompanyReferences(int $organizationId, array $data, bool $creating = true): void
    {
        if ($creating || array_key_exists('owner_user_id', $data)) {
            $this->ensureOwnerBelongsToOrganization($organizationId, $data['owner_user_id'] ?? null);
        }

        if ($creating || array_key_exists('source_id', $data)) {
            $this->ensureSourceBelongsToOrganization($organizationId, $data['source_id'] ?? null);
        }
    }

    private function validateContactReferences(int $organizationId, array $data, bool $creating = true): void
    {
        if ($creating || array_key_exists('owner_user_id', $data)) {
            $this->ensureOwnerBelongsToOrganization($organizationId, $data['owner_user_id'] ?? null);
        }

        if ($creating || array_key_exists('source_id', $data)) {
            $this->ensureSourceBelongsToOrganization($organizationId, $data['source_id'] ?? null);
        }

        if ($creating || array_key_exists('company_id', $data)) {
            $this->ensureCompanyBelongsToOrganization($organizationId, $data['company_id'] ?? null);
        }
    }

    private function validateLeadReferences(int $organizationId, array $data, bool $creating = true): void
    {
        if ($creating || array_key_exists('owner_user_id', $data)) {
            $this->ensureOwnerBelongsToOrganization($organizationId, $data['owner_user_id'] ?? null);
        }

        if ($creating || array_key_exists('source_id', $data)) {
            $this->ensureSourceBelongsToOrganization($organizationId, $data['source_id'] ?? null);
        }

        if ($creating || array_key_exists('company_id', $data)) {
            $this->ensureCompanyBelongsToOrganization($organizationId, $data['company_id'] ?? null);
        }

        if ($creating || array_key_exists('contact_id', $data)) {
            $this->ensureContactBelongsToOrganization($organizationId, $data['contact_id'] ?? null);
        }
    }

    private function validateActivityReferences(int $organizationId, array $data, bool $creating = true): void
    {
        if ($creating || array_key_exists('owner_user_id', $data)) {
            $this->ensureOwnerBelongsToOrganization($organizationId, $data['owner_user_id'] ?? null);
        }

        if ($creating || array_key_exists('company_id', $data)) {
            $this->ensureCompanyBelongsToOrganization($organizationId, $data['company_id'] ?? null);
        }

        if ($creating || array_key_exists('contact_id', $data)) {
            $this->ensureContactBelongsToOrganization($organizationId, $data['contact_id'] ?? null);
        }

        if ($creating || array_key_exists('lead_id', $data)) {
            $this->ensureLeadBelongsToOrganization($organizationId, $data['lead_id'] ?? null);
        }

        if ($creating || array_key_exists('deal_id', $data)) {
            $this->ensureDealBelongsToOrganization($organizationId, $data['deal_id'] ?? null);
        }
    }

    private function ensureCompanyBelongsToOrganization(int $organizationId, mixed $companyId, bool $required = false): void
    {
        if ($this->isEmptyReference($companyId)) {
            if ($required) {
                $this->throwReferenceValidation('company_id', 'crm.validation.company_required');
            }

            return;
        }

        $exists = CrmCompany::query()
            ->forOrganization($organizationId)
            ->whereKey($companyId)
            ->exists();

        if (! $exists) {
            $this->throwReferenceValidation('company_id', 'crm.validation.company_invalid');
        }
    }

    private function ensureContactBelongsToOrganization(int $organizationId, mixed $contactId): void
    {
        if ($this->isEmptyReference($contactId)) {
            return;
        }

        $exists = CrmContact::query()
            ->forOrganization($organizationId)
            ->whereKey($contactId)
            ->exists();

        if (! $exists) {
            $this->throwReferenceValidation('contact_id', 'crm.validation.contact_invalid');
        }
    }

    private function ensureLeadBelongsToOrganization(int $organizationId, mixed $leadId): void
    {
        if ($this->isEmptyReference($leadId)) {
            return;
        }

        $exists = CrmLead::query()
            ->forOrganization($organizationId)
            ->whereKey($leadId)
            ->exists();

        if (! $exists) {
            $this->throwReferenceValidation('lead_id', 'crm.validation.lead_invalid');
        }
    }

    private function ensureDealBelongsToOrganization(int $organizationId, mixed $dealId): void
    {
        if ($this->isEmptyReference($dealId)) {
            return;
        }

        $exists = CrmDeal::query()
            ->forOrganization($organizationId)
            ->whereKey($dealId)
            ->exists();

        if (! $exists) {
            $this->throwReferenceValidation('deal_id', 'crm.validation.deal_invalid');
        }
    }

    private function ensureOwnerBelongsToOrganization(int $organizationId, mixed $ownerUserId): void
    {
        if ($this->isEmptyReference($ownerUserId)) {
            return;
        }

        $exists = DB::table('organization_user')
            ->where('organization_id', $organizationId)
            ->where('user_id', $ownerUserId)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            $this->throwReferenceValidation('owner_user_id', 'crm.validation.owner_invalid');
        }
    }

    private function ensureProjectBelongsToOrganization(int $organizationId, mixed $projectId): void
    {
        if ($this->isEmptyReference($projectId)) {
            return;
        }

        $exists = Project::query()
            ->accessibleByOrganization($organizationId)
            ->whereKey($projectId)
            ->exists();

        if (! $exists) {
            $this->throwReferenceValidation('project_id', 'crm.validation.project_invalid');
        }
    }

    private function ensureContractBelongsToOrganization(int $organizationId, mixed $contractId): void
    {
        if ($this->isEmptyReference($contractId)) {
            return;
        }

        $exists = Contract::query()
            ->forOrganization($organizationId)
            ->whereKey($contractId)
            ->exists();

        if (! $exists) {
            $this->throwReferenceValidation('contract_id', 'crm.validation.contract_invalid');
        }
    }

    private function ensureSourceBelongsToOrganization(int $organizationId, mixed $sourceId): void
    {
        if ($this->isEmptyReference($sourceId)) {
            return;
        }

        $exists = CrmSource::query()
            ->whereKey($sourceId)
            ->where(function (Builder $query) use ($organizationId): void {
                $query->where('organization_id', $organizationId)->orWhereNull('organization_id');
            })
            ->exists();

        if (! $exists) {
            $this->throwReferenceValidation('source_id', 'crm.validation.source_invalid');
        }
    }

    private function normalizeDealPipelineReferences(int $organizationId, array $data, ?CrmDeal $deal): array
    {
        $hasPipeline = array_key_exists('pipeline_id', $data);
        $hasStage = array_key_exists('stage_id', $data);

        if (! $hasPipeline && ! $hasStage && $deal !== null) {
            return $data;
        }

        $pipelineId = $hasPipeline ? ($data['pipeline_id'] ?? null) : null;

        if ($hasPipeline) {
            $this->ensurePipelineBelongsToOrganization($organizationId, $pipelineId);
        }

        if (! $hasStage) {
            return $data;
        }

        $stageId = $data['stage_id'] ?? null;

        if ($this->isEmptyReference($stageId)) {
            return $data;
        }

        $stage = $this->resolvePipelineStage($organizationId, $stageId);

        if (! $this->isEmptyReference($pipelineId) && (string) $stage->pipeline_id !== (string) $pipelineId) {
            $this->throwReferenceValidation('stage_id', 'crm.validation.stage_pipeline_mismatch');
        }

        $data['pipeline_id'] = $stage->pipeline_id;
        $data['stage_code'] = $data['stage_code'] ?? $stage->code;
        $data['pipeline_code'] = $data['pipeline_code'] ?? $stage->pipeline?->code;

        return $data;
    }

    private function ensurePipelineBelongsToOrganization(int $organizationId, mixed $pipelineId): void
    {
        if ($this->isEmptyReference($pipelineId)) {
            return;
        }

        $exists = CrmPipeline::query()
            ->whereKey($pipelineId)
            ->where(function (Builder $query) use ($organizationId): void {
                $query->where('organization_id', $organizationId)->orWhereNull('organization_id');
            })
            ->exists();

        if (! $exists) {
            $this->throwReferenceValidation('pipeline_id', 'crm.validation.pipeline_invalid');
        }
    }

    private function resolvePipelineStage(int $organizationId, mixed $stageId): CrmPipelineStage
    {
        $stage = CrmPipelineStage::query()
            ->whereKey($stageId)
            ->whereHas('pipeline', function (Builder $query) use ($organizationId): void {
                $query->where('organization_id', $organizationId)->orWhereNull('organization_id');
            })
            ->with('pipeline')
            ->first();

        if ($stage === null) {
            $this->throwReferenceValidation('stage_id', 'crm.validation.stage_invalid');
        }

        return $stage;
    }

    private function isEmptyReference(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    private function throwReferenceValidation(string $field, string $translationKey): never
    {
        throw ValidationException::withMessages([
            $field => trans_message($translationKey),
        ]);
    }

    private function ensureSinglePrimaryContact(int $organizationId, array $data, ?string $exceptContactId = null): void
    {
        if (empty($data['is_primary']) || empty($data['company_id'])) {
            return;
        }

        CrmContact::query()
            ->forOrganization($organizationId)
            ->where('company_id', $data['company_id'])
            ->when($exceptContactId !== null, fn (Builder $query) => $query->whereKeyNot($exceptContactId))
            ->update(['is_primary' => false]);
    }

    private function touchRelatedActivity(CrmActivity $activity): void
    {
        $timestamp = $activity->completed_at ?? $activity->created_at ?? now();

        foreach ([
            [CrmCompany::class, $activity->company_id],
            [CrmContact::class, $activity->contact_id],
        ] as [$class, $id]) {
            if ($id !== null) {
                $class::query()->whereKey($id)->update(['last_activity_at' => $timestamp]);
            }
        }

        if ($activity->deal_id !== null && $activity->status === 'planned') {
            CrmDeal::query()->whereKey($activity->deal_id)->update(['next_activity_at' => $activity->due_at]);
        }
    }
}
