<?php

namespace App\BusinessModules\Features\SiteRequests\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestService;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestWorkflowService;
use App\BusinessModules\Features\SiteRequests\Http\Requests\StoreSiteRequestRequest;
use App\BusinessModules\Features\SiteRequests\Http\Requests\UpdateSiteRequestRequest;
use App\BusinessModules\Features\SiteRequests\Http\Requests\ChangeStatusRequest;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestResource;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestCollection;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Admin API контроллер для заявок
 */
class SiteRequestController extends Controller
{
    public function __construct(
        private readonly SiteRequestService $service,
        private readonly SiteRequestWorkflowService $workflowService
    ) {}

    /**
     * Список заявок с пагинацией
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $perPage = min($request->input('per_page', 15), 100);

            $filters = $request->only([
                'status',
                'priority',
                'request_type',
                'project_id',
                'user_id',
                'assigned_to',
                'date_from',
                'date_to',
                'search',
                'overdue',
                'sort_by',
                'sort_dir',
            ]);

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
            \Log::error('site_requests.index.error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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

            $siteRequest = $this->service->find($id, $organizationId);

            if (!$siteRequest) {
                return response()->json([
                    'success' => false,
                    'error' => 'Заявка не найдена',
                ], 404);
            }

            // Загружаем историю
            $siteRequest->load('history.user');

            return response()->json([
                'success' => true,
                'data' => new SiteRequestResource($siteRequest),
                'available_transitions' => $this->workflowService->getAvailableTransitions($siteRequest),
            ]);
        } catch (\Exception $e) {
            \Log::error('site_requests.show.error', [
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
            \Log::error('site_requests.store.error', [
                'data' => $request->validated(),
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
            \Log::error('site_requests.update.error', [
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
     * Удалить заявку
     */
    public function destroy(Request $request, int $id): JsonResponse
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

            $this->service->delete($siteRequest, $userId);

            return response()->json([
                'success' => true,
                'message' => 'Заявка успешно удалена',
            ]);
        } catch (\Exception $e) {
            \Log::error('site_requests.destroy.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось удалить заявку',
            ], 500);
        }
    }

    /**
     * Изменить статус заявки
     */
    public function changeStatus(ChangeStatusRequest $request, int $id): JsonResponse
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

            $updated = $this->service->changeStatus(
                $siteRequest,
                $userId,
                $request->input('status'),
                $request->input('notes')
            );

            return response()->json([
                'success' => true,
                'message' => 'Статус заявки изменен',
                'data' => new SiteRequestResource($updated),
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('site_requests.change_status.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось изменить статус',
            ], 500);
        }
    }

    /**
     * Назначить исполнителя
     */
    public function assign(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $userId = auth()->id();

            $request->validate([
                'user_id' => ['required', 'integer', 'exists:users,id'],
            ]);

            $siteRequest = $this->service->find($id, $organizationId);

            if (!$siteRequest) {
                return response()->json([
                    'success' => false,
                    'error' => 'Заявка не найдена',
                ], 404);
            }

            $updated = $this->service->assign($siteRequest, $userId, $request->input('user_id'));

            return response()->json([
                'success' => true,
                'message' => 'Исполнитель назначен',
                'data' => new SiteRequestResource($updated),
            ]);
        } catch (\Exception $e) {
            \Log::error('site_requests.assign.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось назначить исполнителя',
            ], 500);
        }
    }

    /**
     * Отправить заявку (из черновика)
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
            \Log::error('site_requests.submit.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось отправить заявку',
            ], 500);
        }
    }
}

