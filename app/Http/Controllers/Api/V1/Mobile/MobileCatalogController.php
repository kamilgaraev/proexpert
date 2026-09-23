<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\BusinessModules\Features\Crm\Http\Resources\CrmActivityResource;
use App\BusinessModules\Features\Crm\Http\Resources\CrmCompanyResource;
use App\BusinessModules\Features\Crm\Http\Resources\CrmContactResource;
use App\BusinessModules\Features\Crm\Http\Resources\CrmDealResource;
use App\BusinessModules\Features\Crm\Http\Resources\CrmLeadResource;
use App\BusinessModules\Features\Crm\Services\CrmRegistryService;
use App\BusinessModules\Features\Tenders\Services\TenderRegistryService;
use App\Http\Requests\Api\V1\Mobile\MobileCatalogCrmIndexRequest;
use App\Http\Requests\Api\V1\Mobile\MobileCatalogProjectContextRequest;
use App\Http\Requests\Api\V1\Mobile\MobileCatalogReportIndexRequest;
use App\Http\Requests\Api\V1\Mobile\MobileCatalogTemplateIndexRequest;
use App\Http\Requests\Api\V1\Mobile\MobileCatalogTenderIndexRequest;
use App\Http\Requests\Api\V1\Mobile\StoreMobileCrmActivityRequest;
use App\Http\Resources\Api\V1\Admin\ReportTemplateResource;
use App\Models\ReportFile;
use App\Services\Report\ReportTemplateService;
use App\Services\Mobile\MobileReadCatalogService;
use App\Services\Mobile\MobileCrmActivityService;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use App\Models\User;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\ValidationException;
use Throwable;

final class MobileCatalogController extends Controller
{
    private const CRM = [
        'companies' => [CrmCompanyResource::class, 'crm.companies.view'],
        'contacts' => [CrmContactResource::class, 'crm.contacts.view'],
        'leads' => [CrmLeadResource::class, 'crm.leads.view'],
        'deals' => [CrmDealResource::class, 'crm.deals.view'],
        'activities' => [CrmActivityResource::class, 'crm.activities.view'],
    ];

    public function __construct(
        private readonly CrmRegistryService $crm,
        private readonly TenderRegistryService $tenders,
        private readonly ReportTemplateService $templates,
        private readonly MobileReadCatalogService $readCatalog,
        private readonly MobileCrmActivityService $crmActions,
    ) {}

    public function crmStoreActivity(StoreMobileCrmActivityRequest $request): JsonResponse
    {
        try {
            [$user, $organizationId] = $this->context($request);
            $activity = $this->crmActions->create($user, $organizationId, $request->validated());

            return MobileResponse::success((new CrmActivityResource($activity))->resolve($request), null, 201);
        } catch (ValidationException $exception) {
            return MobileResponse::error(trans_message('mobile_companions.errors.validation_failed'), 422, $exception->errors());
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (ModelNotFoundException) {
            return MobileResponse::error(trans_message('mobile_companions.errors.item_not_found'), 404);
        } catch (Throwable $exception) {
            return $this->failed($request, $exception, 'crm.activity.create');
        }
    }

    public function crmIndex(MobileCatalogCrmIndexRequest $request, string $entity): JsonResponse
    {
        try {
            [$resource, $permission] = $this->crmEntity($entity);
            [$user, $organizationId] = $this->context($request);
            $filters = $request->validated();
            if ($entity === 'deals') {
                $this->assertProjectFilter($filters, $user, $organizationId);
            }
            if (in_array($entity, ['deals', 'activities'], true)) {
                $filters = array_merge($filters, $this->readCatalog->crmListScope($user, $organizationId, $permission));
            } else {
                $this->readCatalog->assertCanView($user, $organizationId, 'crm', [$permission]);
            }
            $perPage = min((int) ($filters['per_page'] ?? 20), 50);
            $paginator = match ($entity) {
                'companies' => $this->crm->paginateCompanies($organizationId, $filters, $perPage),
                'contacts' => $this->crm->paginateContacts($organizationId, $filters, $perPage),
                'leads' => $this->crm->paginateLeads($organizationId, $filters, $perPage),
                'deals' => $this->crm->paginateDeals($organizationId, $filters, $perPage),
                'activities' => $this->crm->paginateActivities($organizationId, $filters, $perPage),
                default => throw new DomainException(trans_message('mobile_companions.errors.module_not_found')),
            };
            $items = collect($paginator->items())
                ->map(static fn ($model): array => (new $resource($model))->resolve($request))
                ->all();

            return MobileResponse::paginated($items, $this->pagination($paginator));
        } catch (ValidationException $exception) {
            return MobileResponse::error(trans_message('mobile_companions.errors.validation_failed'), 422, $exception->errors());
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (ModelNotFoundException) {
            return MobileResponse::error(trans_message('mobile_companions.errors.item_not_found'), 404);
        } catch (Throwable $exception) {
            return $this->failed($request, $exception, 'crm.index');
        }
    }

    public function crmShow(MobileCatalogProjectContextRequest $request, string $entity, string $id): JsonResponse
    {
        try {
            [$resource, $permission] = $this->crmEntity($entity);
            [$user, $organizationId] = $this->context($request);
            $this->readCatalog->assertModuleAccess($organizationId, 'crm');
            $projectId = null;
            if ($entity === 'deals') {
                $validated = $request->validated();
                $projectId = isset($validated['project_id']) ? (int) $validated['project_id'] : null;
                if ($projectId !== null) {
                    $this->assertProjectFilter(['project_id' => $projectId], $user, $organizationId);
                }
            }
            $model = match ($entity) {
                'companies' => $this->crm->findCompany($organizationId, $id),
                'contacts' => $this->crm->findContact($organizationId, $id),
                'leads' => $this->crm->findLead($organizationId, $id),
                'deals' => $this->crm->findDeal($organizationId, $id),
                'activities' => $this->crm->findActivity($organizationId, $id),
                default => throw new DomainException(trans_message('mobile_companions.errors.module_not_found')),
            };
            if ($projectId !== null && (int) $model->project_id !== $projectId) {
                return MobileResponse::error(trans_message('mobile_companions.errors.item_not_found'), 404);
            }
            if (in_array($entity, ['deals', 'activities'], true)) {
                $this->readCatalog->assertCrmRecordView($user, $organizationId, $permission, $model);
            } else {
                $this->readCatalog->assertCanView($user, $organizationId, 'crm', [$permission]);
            }

            return MobileResponse::success(['item' => (new $resource($model))->resolve($request)]);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (ModelNotFoundException) {
            return MobileResponse::error(trans_message('mobile_companions.errors.item_not_found'), 404);
        } catch (Throwable $exception) {
            return $this->failed($request, $exception, 'crm.show');
        }
    }

    public function tenderIndex(MobileCatalogTenderIndexRequest $request): JsonResponse
    {
        try {
            [$user, $organizationId] = $this->context($request);
            $this->readCatalog->assertCanView($user, $organizationId, 'tenders', ['tenders.view']);
            $filters = $request->validated();
            $this->assertProjectFilter($filters, $user, $organizationId);
            $paginator = $this->tenders->paginate($organizationId, $filters, min((int) ($filters['per_page'] ?? 20), 50));
            $canViewAmounts = $this->readCatalog->hasPermission($user, $organizationId, 'tenders.amounts.view');
            $items = collect($paginator->items())
                ->map(fn ($tender): array => $this->tenders->serialize($tender, $canViewAmounts))
                ->all();

            return MobileResponse::paginated($items, $this->pagination($paginator));
        } catch (ValidationException $exception) {
            return MobileResponse::error(trans_message('mobile_companions.errors.validation_failed'), 422, $exception->errors());
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (ModelNotFoundException) {
            return MobileResponse::error(trans_message('mobile_companions.errors.item_not_found'), 404);
        } catch (Throwable $exception) {
            return $this->failed($request, $exception, 'tenders.index');
        }
    }

    public function tenderShow(MobileCatalogProjectContextRequest $request, string $id): JsonResponse
    {
        try {
            [$user, $organizationId] = $this->context($request);
            $this->readCatalog->assertCanView($user, $organizationId, 'tenders', ['tenders.view']);
            $validated = $request->validated();
            $projectId = isset($validated['project_id']) ? (int) $validated['project_id'] : null;
            if ($projectId !== null) {
                $this->readCatalog->assertProjectAccess($user, $organizationId, $projectId);
            }
            $tender = $this->tenders->find($organizationId, $id);
            if ($projectId !== null && (int) $tender->project_id !== $projectId) {
                return MobileResponse::error(trans_message('mobile_companions.errors.item_not_found'), 404);
            }
            $canViewAmounts = $this->readCatalog->hasPermission($user, $organizationId, 'tenders.amounts.view');

            return MobileResponse::success([
                'item' => $this->tenders->serialize($tender, $canViewAmounts, true),
            ]);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (ModelNotFoundException) {
            return MobileResponse::error(trans_message('mobile_companions.errors.item_not_found'), 404);
        } catch (Throwable $exception) {
            return $this->failed($request, $exception, 'tenders.show');
        }
    }

    public function reportsIndex(MobileCatalogReportIndexRequest $request): JsonResponse
    {
        try {
            [$user, $organizationId] = $this->context($request);
            $this->readCatalog->assertCanView($user, $organizationId, 'file-management', ['report_files.view']);
            $filters = $request->validated();
            $paginator = $this->readCatalog->reportFilesQuery($user, $organizationId, $filters)
                ->orderByDesc('created_at')
                ->paginate(min((int) ($filters['per_page'] ?? 20), 50));

            return MobileResponse::paginated(
                collect($paginator->items())->map(fn (ReportFile $file): array => $this->readCatalog->reportFilePayload($file, $organizationId))->all(),
                $this->pagination($paginator),
            );
        } catch (ValidationException $exception) {
            return MobileResponse::error(trans_message('mobile_companions.errors.validation_failed'), 422, $exception->errors());
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->failed($request, $exception, 'reports.index');
        }
    }

    public function reportShow(Request $request, string $id): JsonResponse
    {
        try {
            [$user, $organizationId] = $this->context($request);
            $this->readCatalog->assertCanView($user, $organizationId, 'file-management', ['report_files.view']);
            $file = $this->readCatalog->findReportFile($user, $organizationId, $id);

            return MobileResponse::success(['item' => $this->readCatalog->reportFilePayload($file, $organizationId)]);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (ModelNotFoundException) {
            return MobileResponse::error(trans_message('mobile_companions.errors.item_not_found'), 404);
        } catch (Throwable $exception) {
            return $this->failed($request, $exception, 'reports.show');
        }
    }

    public function templatesIndex(MobileCatalogTemplateIndexRequest $request): JsonResponse
    {
        try {
            [$user, $organizationId] = $this->context($request);
            $this->readCatalog->assertCanView($user, $organizationId, 'report-templates', ['report_templates.view']);
            $paginator = $this->templates->getTemplates($request);
            $items = ReportTemplateResource::collection($paginator->getCollection())->resolve($request);

            return MobileResponse::paginated($items, $this->pagination($paginator));
        } catch (ValidationException $exception) {
            return MobileResponse::error(trans_message('mobile_companions.errors.validation_failed'), 422, $exception->errors());
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->failed($request, $exception, 'templates.index');
        }
    }

    public function templateShow(Request $request, int $id): JsonResponse
    {
        try {
            [$user, $organizationId] = $this->context($request);
            $this->readCatalog->assertCanView($user, $organizationId, 'report-templates', ['report_templates.view']);

            return MobileResponse::success([
                'item' => (new ReportTemplateResource($this->templates->findTemplateById($id, $request)))->resolve($request),
            ]);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->failed($request, $exception, 'templates.show');
        }
    }

    /** @return array{class-string<JsonResource>, string} */
    private function crmEntity(string $entity): array
    {
        return self::CRM[$entity] ?? throw new DomainException(trans_message('mobile_companions.errors.module_not_found'));
    }

    private function context(Request $request): array
    {
        $user = $request->user();
        $organizationId = (int) $request->attributes->get('current_organization_id');
        if (!$user instanceof User || $organizationId <= 0) {
            throw new DomainException(trans_message('mobile_companions.errors.no_organization'));
        }

        return [$user, $organizationId];
    }

    private function assertProjectFilter(array $filters, User $user, int $organizationId): void
    {
        if (!empty($filters['project_id'])) {
            $this->readCatalog->assertProjectAccess($user, $organizationId, (int) $filters['project_id']);
        }
    }

    private function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    private function domainError(DomainException $exception): JsonResponse
    {
        if ($exception->getMessage() === trans_message('mobile_companions.errors.permission_denied')) {
            return MobileResponse::error($exception->getMessage(), 403, null, ['error_code' => 'PERMISSION_DENIED']);
        }

        return MobileResponse::error($exception->getMessage(), 404);
    }

    private function failed(Request $request, Throwable $exception, string $operation): JsonResponse
    {
        Log::error('mobile_catalog.failed', [
            'operation' => $operation,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'user_id' => $request->user()?->id,
            'error' => $exception->getMessage(),
        ]);

        return MobileResponse::error(trans_message('mobile_companions.errors.action_failed'), 500);
    }
}
