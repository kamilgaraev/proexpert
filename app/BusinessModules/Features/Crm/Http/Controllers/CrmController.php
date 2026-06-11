<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Http\Controllers;

use App\BusinessModules\Features\Crm\Http\Requests\CrmActivityRequest;
use App\BusinessModules\Features\Crm\Http\Requests\CrmCompanyRequest;
use App\BusinessModules\Features\Crm\Http\Requests\CrmContactRequest;
use App\BusinessModules\Features\Crm\Http\Requests\CrmDealRequest;
use App\BusinessModules\Features\Crm\Http\Requests\CrmDealStageRequest;
use App\BusinessModules\Features\Crm\Http\Requests\CrmImportConfirmRequest;
use App\BusinessModules\Features\Crm\Http\Requests\CrmImportPreviewRequest;
use App\BusinessModules\Features\Crm\Http\Requests\CrmLeadConvertRequest;
use App\BusinessModules\Features\Crm\Http\Requests\CrmLeadRequest;
use App\BusinessModules\Features\Crm\Http\Requests\CrmListRequest;
use App\BusinessModules\Features\Crm\Http\Requests\CrmMergeRequest;
use App\BusinessModules\Features\Crm\Http\Resources\CrmActivityResource;
use App\BusinessModules\Features\Crm\Http\Resources\CrmCompanyResource;
use App\BusinessModules\Features\Crm\Http\Resources\CrmContactResource;
use App\BusinessModules\Features\Crm\Http\Resources\CrmDealResource;
use App\BusinessModules\Features\Crm\Http\Resources\CrmImportBatchResource;
use App\BusinessModules\Features\Crm\Http\Resources\CrmImportRowResource;
use App\BusinessModules\Features\Crm\Http\Resources\CrmLeadResource;
use App\BusinessModules\Features\Crm\Http\Resources\CrmTimelineEventResource;
use App\BusinessModules\Features\Crm\Models\CrmActivity;
use App\BusinessModules\Features\Crm\Models\CrmCompany;
use App\BusinessModules\Features\Crm\Models\CrmContact;
use App\BusinessModules\Features\Crm\Models\CrmDeal;
use App\BusinessModules\Features\Crm\Models\CrmLead;
use App\BusinessModules\Features\Crm\Services\CrmDuplicateService;
use App\BusinessModules\Features\Crm\Services\CrmImportService;
use App\BusinessModules\Features\Crm\Services\CrmRegistryService;
use App\BusinessModules\Features\Crm\Services\CrmTimelineService;
use App\BusinessModules\Features\Crm\Services\CrmWorkflowService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

use function trans_message;

final class CrmController extends Controller
{
    public function __construct(
        private readonly CrmRegistryService $registry,
        private readonly CrmWorkflowService $workflow,
        private readonly CrmImportService $imports,
        private readonly CrmDuplicateService $duplicates,
        private readonly CrmTimelineService $timeline
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success($this->registry->summary($this->organizationId($request), $this->canViewAmounts($request)));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.summary.error');
        }
    }

    public function references(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success($this->registry->references($this->organizationId($request)));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.references.error');
        }
    }

    public function companies(CrmListRequest $request): JsonResponse
    {
        try {
            $paginator = $this->registry->paginateCompanies($this->organizationId($request), $request->validated(), $this->perPage($request));

            return $this->paginated($paginator, CrmCompanyResource::class);
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.companies.index_error');
        }
    }

    public function showCompany(Request $request, string $id): JsonResponse
    {
        try {
            return AdminResponse::success(new CrmCompanyResource($this->registry->findCompany($this->organizationId($request), $id)));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.companies.show_error');
        }
    }

    public function storeCompany(CrmCompanyRequest $request): JsonResponse
    {
        try {
            $company = $this->registry->createCompany($this->organizationId($request), $request->validated(), $this->actorId($request));

            return AdminResponse::success(new CrmCompanyResource($company), trans_message('crm.companies.created'), 201);
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.companies.store_error');
        }
    }

    public function updateCompany(CrmCompanyRequest $request, string $id): JsonResponse
    {
        try {
            $company = $this->registry->updateCompany($this->organizationId($request), $id, $request->validated(), $this->actorId($request));

            return AdminResponse::success(new CrmCompanyResource($company), trans_message('crm.companies.updated'));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.companies.update_error');
        }
    }

    public function archiveCompany(Request $request, string $id): JsonResponse
    {
        try {
            $company = $this->registry->findCompany($this->organizationId($request), $id);
            $this->registry->archive($company, $this->actorId($request));

            return AdminResponse::success(new CrmCompanyResource($this->registry->findCompany($this->organizationId($request), $id)), trans_message('crm.companies.archived'));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.companies.archive_error');
        }
    }

    public function restoreCompany(Request $request, string $id): JsonResponse
    {
        try {
            $company = $this->registry->findCompany($this->organizationId($request), $id);
            $this->registry->restore($company, $this->actorId($request));

            return AdminResponse::success(new CrmCompanyResource($this->registry->findCompany($this->organizationId($request), $id)), trans_message('crm.companies.restored'));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.companies.restore_error');
        }
    }

    public function contacts(CrmListRequest $request): JsonResponse
    {
        try {
            return $this->paginated(
                $this->registry->paginateContacts($this->organizationId($request), $request->validated(), $this->perPage($request)),
                CrmContactResource::class
            );
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.contacts.index_error');
        }
    }

    public function showContact(Request $request, string $id): JsonResponse
    {
        try {
            return AdminResponse::success(new CrmContactResource($this->registry->findContact($this->organizationId($request), $id)));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.contacts.show_error');
        }
    }

    public function storeContact(CrmContactRequest $request): JsonResponse
    {
        try {
            $contact = $this->registry->createContact($this->organizationId($request), $request->validated(), $this->actorId($request));

            return AdminResponse::success(new CrmContactResource($contact), trans_message('crm.contacts.created'), 201);
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.contacts.store_error');
        }
    }

    public function updateContact(CrmContactRequest $request, string $id): JsonResponse
    {
        try {
            $contact = $this->registry->updateContact($this->organizationId($request), $id, $request->validated(), $this->actorId($request));

            return AdminResponse::success(new CrmContactResource($contact), trans_message('crm.contacts.updated'));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.contacts.update_error');
        }
    }

    public function archiveContact(Request $request, string $id): JsonResponse
    {
        try {
            $contact = $this->registry->findContact($this->organizationId($request), $id);
            $this->registry->archive($contact, $this->actorId($request));

            return AdminResponse::success(new CrmContactResource($this->registry->findContact($this->organizationId($request), $id)), trans_message('crm.contacts.archived'));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.contacts.archive_error');
        }
    }

    public function restoreContact(Request $request, string $id): JsonResponse
    {
        try {
            $contact = $this->registry->findContact($this->organizationId($request), $id);
            $this->registry->restore($contact, $this->actorId($request));

            return AdminResponse::success(new CrmContactResource($this->registry->findContact($this->organizationId($request), $id)), trans_message('crm.contacts.restored'));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.contacts.restore_error');
        }
    }

    public function leads(CrmListRequest $request): JsonResponse
    {
        try {
            return $this->paginated(
                $this->registry->paginateLeads($this->organizationId($request), $request->validated(), $this->perPage($request)),
                CrmLeadResource::class
            );
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.leads.index_error');
        }
    }

    public function showLead(Request $request, string $id): JsonResponse
    {
        try {
            return AdminResponse::success(new CrmLeadResource($this->registry->findLead($this->organizationId($request), $id)));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.leads.show_error');
        }
    }

    public function storeLead(CrmLeadRequest $request): JsonResponse
    {
        try {
            $lead = $this->registry->createLead($this->organizationId($request), $request->validated(), $this->actorId($request));

            return AdminResponse::success(new CrmLeadResource($lead), trans_message('crm.leads.created'), 201);
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.leads.store_error');
        }
    }

    public function updateLead(CrmLeadRequest $request, string $id): JsonResponse
    {
        try {
            $lead = $this->registry->updateLead($this->organizationId($request), $id, $request->validated(), $this->actorId($request));

            return AdminResponse::success(new CrmLeadResource($lead), trans_message('crm.leads.updated'));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.leads.update_error');
        }
    }

    public function qualifyLead(Request $request, string $id): JsonResponse
    {
        try {
            $lead = $this->workflow->qualifyLead($this->organizationId($request), $id, $this->actorId($request));

            return AdminResponse::success(new CrmLeadResource($lead), trans_message('crm.leads.qualified'));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.leads.qualify_error');
        }
    }

    public function convertLead(CrmLeadConvertRequest $request, string $id): JsonResponse
    {
        try {
            $result = $this->workflow->convertLead($this->organizationId($request), $id, $request->validated(), $this->actorId($request));

            return AdminResponse::success([
                'lead' => (new CrmLeadResource($result['lead']))->resolve(),
                'company' => (new CrmCompanyResource($result['company']))->resolve(),
                'contact' => $result['contact'] === null ? null : (new CrmContactResource($result['contact']))->resolve(),
                'deal' => (new CrmDealResource($result['deal']))->resolve(),
            ], trans_message('crm.leads.converted'));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.leads.convert_error');
        }
    }

    public function archiveLead(Request $request, string $id): JsonResponse
    {
        try {
            $lead = $this->registry->findLead($this->organizationId($request), $id);
            $this->registry->archive($lead, $this->actorId($request));

            return AdminResponse::success(new CrmLeadResource($this->registry->findLead($this->organizationId($request), $id)), trans_message('crm.leads.archived'));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.leads.archive_error');
        }
    }

    public function restoreLead(Request $request, string $id): JsonResponse
    {
        try {
            $lead = $this->registry->findLead($this->organizationId($request), $id);
            $this->registry->restore($lead, $this->actorId($request));

            return AdminResponse::success(new CrmLeadResource($this->registry->findLead($this->organizationId($request), $id)), trans_message('crm.leads.restored'));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.leads.restore_error');
        }
    }

    public function deals(CrmListRequest $request): JsonResponse
    {
        try {
            return $this->paginated(
                $this->registry->paginateDeals($this->organizationId($request), $request->validated(), $this->perPage($request)),
                CrmDealResource::class
            );
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.deals.index_error');
        }
    }

    public function showDeal(Request $request, string $id): JsonResponse
    {
        try {
            return AdminResponse::success(new CrmDealResource($this->registry->findDeal($this->organizationId($request), $id)));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.deals.show_error');
        }
    }

    public function storeDeal(CrmDealRequest $request): JsonResponse
    {
        try {
            $deal = $this->registry->createDeal($this->organizationId($request), $request->validated(), $this->actorId($request));

            return AdminResponse::success(new CrmDealResource($deal), trans_message('crm.deals.created'), 201);
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.deals.store_error');
        }
    }

    public function updateDeal(CrmDealRequest $request, string $id): JsonResponse
    {
        try {
            $deal = $this->registry->updateDeal($this->organizationId($request), $id, $request->validated(), $this->actorId($request));

            return AdminResponse::success(new CrmDealResource($deal), trans_message('crm.deals.updated'));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.deals.update_error');
        }
    }

    public function transitionDeal(CrmDealStageRequest $request, string $id): JsonResponse
    {
        try {
            $deal = $this->workflow->transitionDeal($this->organizationId($request), $id, $request->validated(), $this->actorId($request));

            return AdminResponse::success(new CrmDealResource($deal), trans_message('crm.deals.stage_changed'));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.deals.stage_error');
        }
    }

    public function linkDeal(Request $request, string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'project_id' => ['nullable', 'integer'],
                'contract_id' => ['nullable', 'integer'],
            ]);
            $deal = $this->workflow->linkDeal($this->organizationId($request), $id, $validated, $this->actorId($request));

            return AdminResponse::success(new CrmDealResource($deal), trans_message('crm.deals.linked'));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.deals.link_error');
        }
    }

    public function archiveDeal(Request $request, string $id): JsonResponse
    {
        try {
            $deal = $this->registry->findDeal($this->organizationId($request), $id);
            $this->registry->archive($deal, $this->actorId($request));

            return AdminResponse::success(new CrmDealResource($this->registry->findDeal($this->organizationId($request), $id)), trans_message('crm.deals.archived'));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.deals.archive_error');
        }
    }

    public function restoreDeal(Request $request, string $id): JsonResponse
    {
        try {
            $deal = $this->registry->findDeal($this->organizationId($request), $id);
            $this->registry->restore($deal, $this->actorId($request));

            return AdminResponse::success(new CrmDealResource($this->registry->findDeal($this->organizationId($request), $id)), trans_message('crm.deals.restored'));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.deals.restore_error');
        }
    }

    public function activities(CrmListRequest $request): JsonResponse
    {
        try {
            return $this->paginated(
                $this->registry->paginateActivities($this->organizationId($request), $request->validated(), $this->perPage($request)),
                CrmActivityResource::class
            );
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.activities.index_error');
        }
    }

    public function showActivity(Request $request, string $id): JsonResponse
    {
        try {
            return AdminResponse::success(new CrmActivityResource($this->registry->findActivity($this->organizationId($request), $id)));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.activities.show_error');
        }
    }

    public function storeActivity(CrmActivityRequest $request): JsonResponse
    {
        try {
            $activity = $this->registry->createActivity($this->organizationId($request), $request->validated(), $this->actorId($request));

            return AdminResponse::success(new CrmActivityResource($activity), trans_message('crm.activities.created'), 201);
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.activities.store_error');
        }
    }

    public function updateActivity(CrmActivityRequest $request, string $id): JsonResponse
    {
        try {
            $activity = $this->registry->updateActivity($this->organizationId($request), $id, $request->validated(), $this->actorId($request));

            return AdminResponse::success(new CrmActivityResource($activity), trans_message('crm.activities.updated'));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.activities.update_error');
        }
    }

    public function completeActivity(Request $request, string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'completed_at' => ['nullable', 'date'],
                'result' => ['nullable', 'string', 'max:2000'],
            ]);
            $activity = $this->workflow->completeActivity($this->organizationId($request), $id, $validated, $this->actorId($request));

            return AdminResponse::success(new CrmActivityResource($activity), trans_message('crm.activities.completed'));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.activities.complete_error');
        }
    }

    public function archiveActivity(Request $request, string $id): JsonResponse
    {
        try {
            $activity = $this->registry->findActivity($this->organizationId($request), $id);
            $this->registry->archive($activity, $this->actorId($request));

            return AdminResponse::success(new CrmActivityResource($this->registry->findActivity($this->organizationId($request), $id)), trans_message('crm.activities.archived'));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.activities.archive_error');
        }
    }

    public function restoreActivity(Request $request, string $id): JsonResponse
    {
        try {
            $activity = $this->registry->findActivity($this->organizationId($request), $id);
            $this->registry->restore($activity, $this->actorId($request));

            return AdminResponse::success(new CrmActivityResource($this->registry->findActivity($this->organizationId($request), $id)), trans_message('crm.activities.restored'));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.activities.restore_error');
        }
    }

    public function duplicateCandidates(CrmListRequest $request, string $entityType): JsonResponse
    {
        try {
            return AdminResponse::success([
                'entity_type' => $entityType,
                'items' => $this->duplicates->candidates($this->organizationId($request), $entityType, $request->validated()),
            ]);
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.duplicates.index_error');
        }
    }

    public function merge(CrmMergeRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $model = $this->duplicates->merge(
                $this->organizationId($request),
                $validated['entity_type'],
                $validated['master_id'],
                $validated['duplicate_id'],
                $validated['reason'] ?? null,
                $this->actorId($request)
            );

            $resource = $model instanceof CrmCompany
                ? new CrmCompanyResource($model)
                : new CrmContactResource($model);

            return AdminResponse::success($resource, trans_message('crm.merge.completed'));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.merge.error');
        }
    }

    public function importPreview(CrmImportPreviewRequest $request): JsonResponse
    {
        try {
            $batch = $this->imports->preview(
                $this->organizationId($request),
                $request->file('file'),
                (string) $request->validated('entity_type'),
                $request->validated('mapping') ?? [],
                $this->actorId($request)
            );

            return AdminResponse::success(new CrmImportBatchResource($batch), trans_message('crm.import.preview_created'), 201);
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.import.preview_error');
        }
    }

    public function importShow(Request $request, string $batchId): JsonResponse
    {
        try {
            return AdminResponse::success(new CrmImportBatchResource($this->imports->findBatch($this->organizationId($request), $batchId)));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.import.show_error');
        }
    }

    public function importRows(Request $request, string $batchId): JsonResponse
    {
        try {
            return $this->paginated(
                $this->imports->paginateRows($this->organizationId($request), $batchId, $this->perPage($request)),
                CrmImportRowResource::class
            );
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.import.rows_error');
        }
    }

    public function importConfirm(CrmImportConfirmRequest $request, string $batchId): JsonResponse
    {
        try {
            $batch = $this->imports->confirm(
                $this->organizationId($request),
                $batchId,
                $request->validated('decisions') ?? [],
                $this->actorId($request)
            );

            return AdminResponse::success(new CrmImportBatchResource($batch), trans_message('crm.import.confirmed'));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.import.confirm_error');
        }
    }

    public function importCancel(Request $request, string $batchId): JsonResponse
    {
        try {
            $batch = $this->imports->cancel($this->organizationId($request), $batchId);

            return AdminResponse::success(new CrmImportBatchResource($batch), trans_message('crm.import.cancelled'));
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.import.cancel_error');
        }
    }

    public function timeline(Request $request, string $entityType, string $entityId): JsonResponse
    {
        try {
            return $this->paginated(
                $this->timeline->paginate($this->organizationId($request), $entityType, $entityId, $this->perPage($request)),
                CrmTimelineEventResource::class
            );
        } catch (Throwable $e) {
            return $this->failure($e, 'crm.timeline.error');
        }
    }

    private function paginated(LengthAwarePaginator $paginator, string $resourceClass): JsonResponse
    {
        return AdminResponse::paginated(
            $resourceClass::collection($paginator->getCollection()),
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ]
        );
    }

    private function organizationId(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id');
    }

    private function actorId(Request $request): ?int
    {
        return $request->user()?->id;
    }

    private function canViewAmounts(Request $request): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        return $user->can('crm.amounts.view', [
            'organization_id' => $this->organizationId($request),
        ]);
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->input('per_page', 20), 1), 100);
    }

    private function failure(Throwable $e, string $translationKey): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return AdminResponse::error($this->validationMessage($e, $translationKey), 422, $e->errors());
        }

        if ($e instanceof ModelNotFoundException) {
            return AdminResponse::error(trans_message('crm.errors.not_found'), 404);
        }

        Log::error($translationKey, [
            'user_id' => auth()->id(),
            'message' => $e->getMessage(),
        ]);

        return AdminResponse::error(trans_message($translationKey), 500);
    }

    private function validationMessage(ValidationException $e, string $translationKey): string
    {
        foreach ($e->errors() as $messages) {
            if (is_array($messages) && isset($messages[0]) && is_string($messages[0])) {
                return $messages[0];
            }
        }

        return trans_message($translationKey);
    }
}
