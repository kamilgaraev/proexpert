<?php

namespace App\BusinessModules\Features\SiteRequests\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestService;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestTemplateService;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestCalendarService;
use App\BusinessModules\Features\SiteRequests\Http\Requests\StoreSiteRequestRequest;
use App\BusinessModules\Features\SiteRequests\Http\Requests\UpdateSiteRequestRequest;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestResource;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestCollection;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestTemplateResource;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestCalendarEventResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

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

            $perPage = min($request->input('per_page', 15), 50);

            $filters = $request->only([
                'status',
                'priority',
                'request_type',
                'project_id',
            ]);

            // Мобильный API показывает только заявки текущего пользователя
            $filters['user_id'] = $userId;

            $requests = $this->service->paginate($organizationId, $perPage, $filters);

            return response()->json([
                'success' => true,
                'data' => new SiteRequestCollection($requests),
                'meta' => [
                    'current_page' => $requests->currentPage(),
                    'per_page' => $requests->perPage(),
                    'total' => $requests->total(),
                    'last_page' => $requests->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('site_requests.mobile.index.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить заявки',
            ], 500);
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

            $siteRequest = $this->service->find($id, $organizationId);

            if (!$siteRequest) {
                return response()->json([
                    'success' => false,
                    'error' => 'Заявка не найдена',
                ], 404);
            }

            // Проверяем, что заявка принадлежит пользователю
            if (!$siteRequest->belongsToUser($userId) && !$siteRequest->isAssignedTo($userId)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Нет доступа к этой заявке',
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => new SiteRequestResource($siteRequest),
            ]);
        } catch (\Exception $e) {
            \Log::error('site_requests.mobile.show.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить заявку',
            ], 500);
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

            $siteRequest = $this->service->create(
                $organizationId,
                $userId,
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'message' => 'Заявка успешно создана',
                'data' => new SiteRequestResource($siteRequest),
            ], 201);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('site_requests.mobile.store.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось создать заявку',
            ], 500);
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

            $siteRequest = $this->service->find($id, $organizationId);

            if (!$siteRequest) {
                return response()->json([
                    'success' => false,
                    'error' => 'Заявка не найдена',
                ], 404);
            }

            // Проверяем, что заявка принадлежит пользователю
            if (!$siteRequest->belongsToUser($userId)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Вы можете редактировать только свои заявки',
                ], 403);
            }

            $updated = $this->service->update($siteRequest, $userId, $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Заявка успешно обновлена',
                'data' => new SiteRequestResource($updated),
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('site_requests.mobile.update.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось обновить заявку',
            ], 500);
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

            $siteRequest = $this->service->find($id, $organizationId);

            if (!$siteRequest) {
                return response()->json([
                    'success' => false,
                    'error' => 'Заявка не найдена',
                ], 404);
            }

            if (!$siteRequest->belongsToUser($userId)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Вы можете отменять только свои заявки',
                ], 403);
            }

            $updated = $this->service->cancel($siteRequest, $userId, $request->input('notes'));

            return response()->json([
                'success' => true,
                'message' => 'Заявка отменена',
                'data' => new SiteRequestResource($updated),
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('site_requests.mobile.cancel.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось отменить заявку',
            ], 500);
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

            $siteRequest = $this->service->find($id, $organizationId);

            if (!$siteRequest) {
                return response()->json([
                    'success' => false,
                    'error' => 'Заявка не найдена',
                ], 404);
            }

            if (!$siteRequest->belongsToUser($userId)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Вы можете отправлять только свои заявки',
                ], 403);
            }

            $updated = $this->service->submit($siteRequest, $userId);

            return response()->json([
                'success' => true,
                'message' => 'Заявка отправлена на обработку',
                'data' => new SiteRequestResource($updated),
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('site_requests.mobile.submit.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось отправить заявку',
            ], 500);
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

            $siteRequest = $this->service->find($id, $organizationId);

            if (!$siteRequest) {
                return response()->json([
                    'success' => false,
                    'error' => 'Заявка не найдена',
                ], 404);
            }

            if (!$siteRequest->belongsToUser($userId)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Вы можете подтверждать только свои заявки',
                ], 403);
            }

            $updated = $this->service->complete($siteRequest, $userId, $request->input('notes'));

            return response()->json([
                'success' => true,
                'message' => 'Выполнение заявки подтверждено',
                'data' => new SiteRequestResource($updated),
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('site_requests.mobile.complete.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось подтвердить выполнение',
            ], 500);
        }
    }

    /**
     * Список шаблонов
     */
    public function templates(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $templates = $this->templateService->getPopularTemplates($organizationId, 20);

            return response()->json([
                'success' => true,
                'data' => SiteRequestTemplateResource::collection($templates),
            ]);
        } catch (\Exception $e) {
            \Log::error('site_requests.mobile.templates.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить шаблоны',
            ], 500);
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

            $validated = $request->validate([
                'project_id' => ['required', 'integer', 'exists:projects,id'],
            ]);

            $siteRequest = $this->templateService->createFromTemplate(
                $templateId,
                $organizationId,
                $userId,
                $validated['project_id']
            );

            return response()->json([
                'success' => true,
                'message' => 'Заявка создана из шаблона',
                'data' => new SiteRequestResource($siteRequest),
            ], 201);
        } catch (\InvalidArgumentException | \DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('site_requests.mobile.create_from_template.error', [
                'template_id' => $templateId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось создать заявку из шаблона',
            ], 500);
        }
    }

    /**
     * Календарь заявок
     */
    public function calendar(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

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

            return response()->json([
                'success' => true,
                'data' => SiteRequestCalendarEventResource::collection($events),
                'count' => $events->count(),
            ]);
        } catch (\Exception $e) {
            \Log::error('site_requests.mobile.calendar.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить календарь',
            ], 500);
        }
    }
}

