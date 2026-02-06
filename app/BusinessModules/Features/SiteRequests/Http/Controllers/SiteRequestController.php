<?php

namespace App\BusinessModules\Features\SiteRequests\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestService;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestWorkflowService;
use App\BusinessModules\Features\SiteRequests\Http\Requests\StoreSiteRequestRequest;
use App\BusinessModules\Features\SiteRequests\Http\Requests\UpdateSiteRequestRequest;
use App\BusinessModules\Features\SiteRequests\Http\Requests\UpdateSiteRequestGroupRequest;
use App\BusinessModules\Features\SiteRequests\Http\Requests\ChangeStatusRequest;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestResource;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestCollection;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestGroupResource;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

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

            return AdminResponse::success(
                new SiteRequestCollection($requests)
            );
        } catch (\Exception $e) {
            Log::error('site_requests.index.error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans('site_requests.list_error'), 500);
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
                return AdminResponse::error(trans('site_requests.not_found'), 404);
            }

            // Загружаем историю
            $siteRequest->load('history.user');

            $resource = (new SiteRequestResource($siteRequest))->resolve($request);
            $resource['available_transitions'] = $this->workflowService->getAvailableTransitions($siteRequest);

            return AdminResponse::success($resource);
        } catch (\Exception $e) {
            Log::error('site_requests.show.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans('site_requests.show_error'), 500);
        }
    }

    /**
     * Показать группу заявок
     */
    public function showGroup(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $group = $this->service->findGroup($id, $organizationId);

            if (!$group) {
                return AdminResponse::error(trans('site_requests.group_not_found'), 404);
            }

            return AdminResponse::success(new SiteRequestGroupResource($group));
        } catch (\Exception $e) {
            Log::error('site_requests.show_group.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans('site_requests.group_show_error'), 500);
        }
    }

    /**
     * Обновить группу заявок (включая состав материалов)
     */
    public function updateGroup(UpdateSiteRequestGroupRequest $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $userId = auth()->id();

            $group = $this->service->findGroup($id, $organizationId);

            if (!$group) {
                return AdminResponse::error(trans('site_requests.group_not_found'), 404);
            }

            if ($group->status !== \App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum::DRAFT) {
                return AdminResponse::error(trans('site_requests.group_not_editable'), 422);
            }

            $updatedGroup = $this->service->updateGroup($group, $userId, $request->validated());

            return AdminResponse::success(
                new SiteRequestGroupResource($updatedGroup),
                trans('site_requests.group_updated_success')
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('site_requests.update_group.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans('site_requests.group_update_error'), 500);
        }
    }

    /**
     * Создать заявку (или пакет заявок)
     */
    public function store(StoreSiteRequestRequest $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $userId = auth()->id();
            $data = $request->validated();

            // Обработка массового создания (создание группы)
            if (isset($data['materials']) && is_array($data['materials'])) {
                
                $items = [];
                foreach ($data['materials'] as $material) {
                    // Маппинг данных
                    $itemData = [
                        'material_name' => $material['name'] ?? null,
                        'material_quantity' => $material['quantity'] ?? null,
                        'material_unit' => $material['unit'] ?? null,
                        'material_id' => $material['material_id'] ?? null,
                        'note' => $material['note'] ?? null,
                    ];
                    
                    // Формируем заголовок для каждого элемента
                    $itemData['title'] = ($data['title'] ?? 'Заявка') . 
                                        ($itemData['material_name'] ? ' - ' . $itemData['material_name'] : '');
                    
                    $items[] = $itemData;
                }

                $group = $this->service->createBatch($organizationId, $userId, $data, $items);
                
                return AdminResponse::success(
                    new SiteRequestGroupResource($group),
                    trans('site_requests.batch_created_success', ['count' => $group->requests->count()]),
                    201
                );
            }

            // Стандартное создание одной заявки
            $siteRequest = $this->service->create(
                $organizationId,
                $userId,
                $data
            );

            return AdminResponse::success(
                new SiteRequestResource($siteRequest),
                trans('site_requests.created_success'),
                201
            );

        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('site_requests.store.error', [
                'data' => $request->validated(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans('site_requests.store_error'), 500);
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
                return AdminResponse::error(trans('site_requests.not_found'), 404);
            }

            $updated = $this->service->update($siteRequest, $userId, $request->validated());

            return AdminResponse::success(
                new SiteRequestResource($updated),
                trans('site_requests.updated_success')
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('site_requests.update.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans('site_requests.update_error'), 500);
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
                return AdminResponse::error(trans('site_requests.not_found'), 404);
            }

            $this->service->delete($siteRequest, $userId);

            return AdminResponse::success(
                null,
                trans('site_requests.deleted_success')
            );
        } catch (\Exception $e) {
            Log::error('site_requests.destroy.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans('site_requests.destroy_error'), 500);
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
                return AdminResponse::error(trans('site_requests.not_found'), 404);
            }

            $updated = $this->service->changeStatus(
                $siteRequest,
                $userId,
                $request->input('status'),
                $request->input('notes')
            );

            return AdminResponse::success(
                new SiteRequestResource($updated),
                trans('site_requests.change_status_success')
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('site_requests.change_status.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans('site_requests.change_status_error'), 500);
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
                return AdminResponse::error(trans('site_requests.not_found'), 404);
            }

            $updated = $this->service->assign($siteRequest, $userId, $request->input('user_id'));

            return AdminResponse::success(
                new SiteRequestResource($updated),
                trans('site_requests.assigned_success')
            );
        } catch (\Exception $e) {
            Log::error('site_requests.assign.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans('site_requests.assign_error'), 500);
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
                return AdminResponse::error(trans('site_requests.not_found'), 404);
            }

            $updated = $this->service->submit($siteRequest, $userId);

            return AdminResponse::success(
                new SiteRequestResource($updated),
                trans('site_requests.submitted_success')
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('site_requests.submit.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans('site_requests.submit_error'), 500);
        }
    }

    /**
     * Отправить группу заявок (из черновика)
     */
    public function submitGroup(Request $request, int $groupId): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $userId = auth()->id();

            $group = $this->service->findGroup($groupId, $organizationId);

            if (!$group) {
                return AdminResponse::error(trans('site_requests.group_not_found'), 404);
            }

            $updatedGroup = $this->service->submitGroup($group, $userId);

            return AdminResponse::success(
                new SiteRequestGroupResource($updatedGroup),
                trans('site_requests.group_submitted_success')
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('site_requests.submit_group.error', [
                'id' => $groupId,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans('site_requests.group_submit_error'), 500);
        }
    }
}
