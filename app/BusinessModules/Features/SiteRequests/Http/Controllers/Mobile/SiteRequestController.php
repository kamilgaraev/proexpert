<?php

namespace App\BusinessModules\Features\SiteRequests\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestService;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestTemplateService;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestCalendarService;
use App\BusinessModules\Features\SiteRequests\Http\Requests\StoreSiteRequestRequest;
use App\BusinessModules\Features\SiteRequests\Http\Requests\UpdateSiteRequestRequest;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestResource;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestCollection;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestTemplateResource;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestCalendarEventResource;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Enums\PersonnelTypeEnum;
use App\BusinessModules\Features\SiteRequests\Enums\EquipmentTypeEnum;
use App\Models\MeasurementUnit;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Mobile API контроллер для заявок (для прорабов)
 */
class SiteRequestController extends Controller
{
    public function __construct(
        private readonly SiteRequestService $service,
        private readonly SiteRequestTemplateService $templateService,
        private readonly SiteRequestCalendarService $calendarService
    ) {}

    /**
     * Список заявок прораба
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $userId = auth()->id();

            if (!$organizationId) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $perPage = min((int) $request->input('per_page', 15), 50);

            $filters = $request->only([
                'status',
                'priority',
                'request_type',
                'project_id',
            ]);

            // Мобильный API показывает только заявки текущего пользователя
            $filters['user_id'] = $userId;

            $requests = $this->service->paginate($organizationId, $perPage, $filters);

            return MobileResponse::success(new SiteRequestCollection($requests));
        } catch (\Exception $e) {
            Log::error('site_requests.mobile.index.error', [
                'userId' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return MobileResponse::error(trans_message('site_requests::mobile.index_error'), 500);
        }
    }

    /**
     * Показать заявку
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $userId = auth()->id();

            if (!$organizationId) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $siteRequest = $this->service->find($id, $organizationId);

            if (!$siteRequest) {
                return MobileResponse::error(trans_message('site_requests::mobile.not_found'), 404);
            }

            // Проверяем, что заявка принадлежит пользователю
            if (!$siteRequest->belongsToUser($userId) && !$siteRequest->isAssignedTo($userId)) {
                return MobileResponse::error(trans_message('site_requests::mobile.access_denied'), 403);
            }

            return MobileResponse::success(new SiteRequestResource($siteRequest));
        } catch (\Exception $e) {
            Log::error('site_requests.mobile.show.error', [
                'id' => $id,
                'userId' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return MobileResponse::error(trans_message('site_requests::mobile.show_error'), 500);
        }
    }

    /**
     * Создать заявку
     */
    public function store(StoreSiteRequestRequest $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $userId = auth()->id();

            if (!$organizationId) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $siteRequest = $this->service->create(
                $organizationId,
                $userId,
                $request->validated()
            );

            return MobileResponse::success(
                new SiteRequestResource($siteRequest),
                trans_message('site_requests::mobile.store_success'),
                201
            );
        } catch (\DomainException $e) {
            return MobileResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('site_requests.mobile.store.error', [
                'userId' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return MobileResponse::error(trans_message('site_requests::mobile.store_error'), 500);
        }
    }

    /**
     * Обновить заявку
     */
    public function update(UpdateSiteRequestRequest $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $userId = auth()->id();

            if (!$organizationId) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $siteRequest = $this->service->find($id, $organizationId);

            if (!$siteRequest) {
                return MobileResponse::error(trans_message('site_requests::mobile.not_found'), 404);
            }

            // Проверяем, что заявка принадлежит пользователю
            if (!$siteRequest->belongsToUser($userId)) {
                return MobileResponse::error(trans_message('site_requests::mobile.edit_only_own'), 403);
            }

            $updated = $this->service->update($siteRequest, $userId, $request->validated());

            return MobileResponse::success(
                new SiteRequestResource($updated),
                trans_message('site_requests::mobile.update_success')
            );
        } catch (\DomainException $e) {
            return MobileResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('site_requests.mobile.update.error', [
                'id' => $id,
                'userId' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return MobileResponse::error(trans_message('site_requests::mobile.update_error'), 500);
        }
    }

    /**
     * Отменить заявку
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $userId = auth()->id();

            if (!$organizationId) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $siteRequest = $this->service->find($id, $organizationId);

            if (!$siteRequest) {
                return MobileResponse::error(trans_message('site_requests::mobile.not_found'), 404);
            }

            if (!$siteRequest->belongsToUser($userId)) {
                return MobileResponse::error(trans_message('site_requests::mobile.cancel_only_own'), 403);
            }

            $updated = $this->service->cancel($siteRequest, $userId, $request->input('notes'));

            return MobileResponse::success(
                new SiteRequestResource($updated),
                trans_message('site_requests::mobile.cancel_success')
            );
        } catch (\DomainException $e) {
            return MobileResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('site_requests.mobile.cancel.error', [
                'id' => $id,
                'userId' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return MobileResponse::error(trans_message('site_requests::mobile.cancel_error'), 500);
        }
    }

    /**
     * Отправить заявку
     */
    public function submit(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $userId = auth()->id();

            if (!$organizationId) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $siteRequest = $this->service->find($id, $organizationId);

            if (!$siteRequest) {
                return MobileResponse::error(trans_message('site_requests::mobile.not_found'), 404);
            }

            if (!$siteRequest->belongsToUser($userId)) {
                return MobileResponse::error(trans_message('site_requests::mobile.submit_only_own'), 403);
            }

            $updated = $this->service->submit($siteRequest, $userId);

            return MobileResponse::success(
                new SiteRequestResource($updated),
                trans_message('site_requests::mobile.submit_success')
            );
        } catch (\DomainException $e) {
            return MobileResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('site_requests.mobile.submit.error', [
                'id' => $id,
                'userId' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return MobileResponse::error(trans_message('site_requests::mobile.submit_error'), 500);
        }
    }

    /**
     * Подтвердить выполнение
     */
    public function complete(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $userId = auth()->id();

            if (!$organizationId) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $siteRequest = $this->service->find($id, $organizationId);

            if (!$siteRequest) {
                return MobileResponse::error(trans_message('site_requests::mobile.not_found'), 404);
            }

            if (!$siteRequest->belongsToUser($userId)) {
                return MobileResponse::error(trans_message('site_requests::mobile.complete_only_own'), 403);
            }

            $updated = $this->service->complete($siteRequest, $userId, $request->input('notes'));

            return MobileResponse::success(
                new SiteRequestResource($updated),
                trans_message('site_requests::mobile.complete_success')
            );
        } catch (\DomainException $e) {
            return MobileResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('site_requests.mobile.complete.error', [
                'id' => $id,
                'userId' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return MobileResponse::error(trans_message('site_requests::mobile.complete_error'), 500);
        }
    }

    /**
     * Список шаблонов
     */
    public function templates(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            if (!$organizationId) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $templates = $this->templateService->getPopularTemplates($organizationId, 20);

            return MobileResponse::success(SiteRequestTemplateResource::collection($templates));
        } catch (\Exception $e) {
            Log::error('site_requests.mobile.templates.error', [
                'userId' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return MobileResponse::error(trans_message('site_requests::mobile.templates_error'), 500);
        }
    }

    /**
     * Создать заявку из шаблона
     */
    public function createFromTemplate(Request $request, int $templateId): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $userId = auth()->id();

            if (!$organizationId) {
                return MobileResponse::error(__('site_requests::mobile.no_organization'), 400);
            }

            $validated = $request->validate([
                'project_id' => ['required', 'integer', 'exists:projects,id'],
            ]);

            $siteRequest = $this->templateService->createFromTemplate(
                $templateId,
                $organizationId,
                $userId,
                $validated['project_id']
            );

            return MobileResponse::success(
                new SiteRequestResource($siteRequest),
                trans_message('site_requests::mobile.from_template_success'),
                201
            );
        } catch (\InvalidArgumentException | \DomainException $e) {
            return MobileResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('site_requests.mobile.create_from_template.error', [
                'template_id' => $templateId,
                'userId' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return MobileResponse::error(trans_message('site_requests::mobile.from_template_error'), 500);
        }
    }

    /**
     * Календарь заявок
     */
    public function calendar(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            if (!$organizationId) {
                return MobileResponse::error(__('site_requests::mobile.no_organization'), 400);
            }

            $request->validate([
                'start_date' => ['required', 'date'],
                'end_date' => ['required', 'date', 'after_or_equal:start_date'],
                'project_id' => ['nullable', 'integer'],
            ]);

            $startDate = Carbon::parse($request->input('start_date'));
            $endDate = Carbon::parse($request->input('end_date'));
            $projectId = $request->input('project_id');

            $events = $this->calendarService->getCalendarEvents(
                $organizationId,
                $startDate,
                $endDate,
                $projectId
            );

            return MobileResponse::success(SiteRequestCalendarEventResource::collection($events));
        } catch (\Exception $e) {
            Log::error('site_requests.mobile.calendar.error', [
                'userId' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return MobileResponse::error(trans_message('site_requests::mobile.calendar_error'), 500);
        }
    }

    /**
     * Справочники для создания заявок
     */
    public function meta(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            return MobileResponse::success([
                'request_types' => SiteRequestTypeEnum::options(),
                'personnel_types' => PersonnelTypeEnum::options(),
                'equipment_types' => EquipmentTypeEnum::options(),
                'units' => MeasurementUnit::where(function ($query) use ($organizationId) {
                    $query->where('organization_id', $organizationId)
                          ->orWhere('is_system', true);
                })->get(['id', 'name', 'short_name', 'type']),
            ]);
        } catch (\Exception $e) {
            Log::error('site_requests.mobile.meta.error', [
                'userId' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return MobileResponse::error(trans_message('site_requests::mobile.meta_error'), 500);
        }
    }
}
