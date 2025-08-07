<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\SiteRequest\SiteRequestService;
use App\Http\Requests\Api\V1\Admin\SiteRequest\StoreSiteRequestRequest;
use App\Http\Requests\Api\V1\Admin\SiteRequest\UpdateSiteRequestRequest;
use App\Http\Resources\Api\V1\Admin\SiteRequest\SiteRequestResource;
use App\Http\Resources\Api\V1\Admin\SiteRequest\SiteRequestCollection;
use App\Models\SiteRequest;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use App\Exceptions\BusinessLogicException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SiteRequestController extends Controller
{
    protected SiteRequestService $siteRequestService;

    public function __construct(SiteRequestService $siteRequestService)
    {
        $this->siteRequestService = $siteRequestService;
        $this->middleware('can:access-admin-panel');
    }

    /**
     * Получить список заявок с объекта для админской панели
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->input('organization_id') ?? Auth::user()->current_organization_id;
            
            if (!$organizationId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Organization context is required.'
                ], 400);
            }

            $filters = [
                'organization_id' => $organizationId,
                'request_type' => $request->input('request_type'),
                'status' => $request->input('status'),
                'project_id' => $request->input('project_id'),
                'priority' => $request->input('priority'),
                'personnel_type' => $request->input('personnel_type'),
                'search' => $request->input('search'),
            ];

            $perPage = $request->input('per_page', 15);
            $siteRequests = $this->siteRequestService->getFilteredSiteRequests($filters, $perPage);

            return response()->json([
                'success' => true,
                'data' => new SiteRequestCollection($siteRequests)
            ]);
        } catch (BusinessLogicException $e) {
            Log::error('Admin SiteRequest index error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], $e->getCode());
        } catch (\Exception $e) {
            Log::error('Admin SiteRequest index unexpected error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Произошла ошибка при получении заявок'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Получить конкретную заявку
     */
    public function show(SiteRequest $siteRequest): JsonResponse
    {
        try {
            // Проверяем доступ к заявке через организацию
            if ($siteRequest->organization_id !== Auth::user()->current_organization_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нет доступа к данной заявке'
                ], Response::HTTP_FORBIDDEN);
            }

            return response()->json([
                'success' => true,
                'data' => new SiteRequestResource($siteRequest)
            ]);
        } catch (\Exception $e) {
            Log::error('Admin SiteRequest show error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Произошла ошибка при получении заявки'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Создать новую заявку (админ может создавать от имени других пользователей)
     */
    public function store(StoreSiteRequestRequest $request): JsonResponse
    {
        try {
            $siteRequestDTO = $request->toDTO();
            $siteRequest = $this->siteRequestService->createSiteRequest($siteRequestDTO);

            return response()->json([
                'success' => true,
                'message' => 'Заявка успешно создана',
                'data' => new SiteRequestResource($siteRequest)
            ], Response::HTTP_CREATED);
        } catch (BusinessLogicException $e) {
            Log::error('Admin SiteRequest store error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], $e->getCode());
        } catch (\Exception $e) {
            Log::error('Admin SiteRequest store unexpected error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Произошла ошибка при создании заявки'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Обновить заявку
     */
    public function update(UpdateSiteRequestRequest $request, SiteRequest $siteRequest): JsonResponse
    {
        try {
            // Проверяем доступ к заявке через организацию
            if ($siteRequest->organization_id !== Auth::user()->current_organization_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нет доступа к данной заявке'
                ], Response::HTTP_FORBIDDEN);
            }

            $siteRequestDTO = $request->toDTO();
            $updatedSiteRequest = $this->siteRequestService->updateSiteRequest($siteRequest, $siteRequestDTO);

            return response()->json([
                'success' => true,
                'message' => 'Заявка успешно обновлена',
                'data' => new SiteRequestResource($updatedSiteRequest)
            ]);
        } catch (BusinessLogicException $e) {
            Log::error('Admin SiteRequest update error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], $e->getCode());
        } catch (\Exception $e) {
            Log::error('Admin SiteRequest update unexpected error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Произошла ошибка при обновлении заявки'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Удалить заявку
     */
    public function destroy(SiteRequest $siteRequest): JsonResponse
    {
        try {
            // Проверяем доступ к заявке через организацию
            if ($siteRequest->organization_id !== Auth::user()->current_organization_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нет доступа к данной заявке'
                ], Response::HTTP_FORBIDDEN);
            }

            $this->siteRequestService->deleteSiteRequest($siteRequest);

            return response()->json([
                'success' => true,
                'message' => 'Заявка успешно удалена'
            ]);
        } catch (BusinessLogicException $e) {
            Log::error('Admin SiteRequest destroy error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], $e->getCode());
        } catch (\Exception $e) {
            Log::error('Admin SiteRequest destroy unexpected error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Произошла ошибка при удалении заявки'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Получить статистику заявок для дашборда
     */
    public function getStats(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->input('organization_id') ?? Auth::user()->current_organization_id;
            
            if (!$organizationId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Organization context is required.'
                ], 400);
            }

            $stats = $this->siteRequestService->getSiteRequestStats($organizationId);

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            Log::error('Admin SiteRequest stats error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Произошла ошибка при получении статистики'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}