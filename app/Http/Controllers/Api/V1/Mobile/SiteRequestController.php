<?php

namespace App\Http\Controllers\Api\V1\Mobile; // Контроллер для мобильного API

use App\Http\Controllers\Controller;
use App\Services\SiteRequest\SiteRequestService;
use App\Http\Requests\Api\V1\Mobile\SiteRequest\StoreSiteRequestRequest;
use App\Http\Requests\Api\V1\Mobile\SiteRequest\UpdateSiteRequestRequest;
use App\Http\Resources\Api\V1\Mobile\SiteRequest\SiteRequestResource;
use App\Http\Resources\Api\V1\Mobile\SiteRequest\SiteRequestCollection;
use App\Models\SiteRequest; // Для route model binding
use App\Models\File;        // Для route model binding в deleteAttachment
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use App\Exceptions\BusinessLogicException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log; // Для логирования ошибок

class SiteRequestController extends Controller
{
    protected SiteRequestService $siteRequestService;

    public function __construct(SiteRequestService $siteRequestService)
    {
        $this->siteRequestService = $siteRequestService;
        // Middleware для аутентификации и прав доступа (например, 'auth:api_mobile', 'can:access-foreman-features')
        // $this->middleware(['auth:api_mobile', 'organization.context']); // jwt.auth и can:access-mobile-app уже есть в routes/api.php
    }

    public function index(Request $request): SiteRequestCollection
    {
        $organizationId = Auth::user()->current_organization_id;
        $userId = Auth::id(); // Прораб видит только свои заявки или все по проекту (зависит от требований)

        $filters = $request->query();
        $filters['organization_id'] = $organizationId;
        // $filters['user_id'] = $userId; // Раскомментировать, если прораб видит только свои заявки
        // Если прораб видит все заявки по своим проектам, то фильтр по project_id должен быть обязательным
        // или нужно получить список проектов, к которым у прораба есть доступ.
        // Пока оставим возможность просмотра всех заявок организации (или фильтрация по project_id из $request)

        $sortBy = $request->query('sortBy', 'created_at');
        $sortDirection = $request->query('sortDirection', 'desc');
        $perPage = $request->query('perPage', 15);

        $siteRequests = $this->siteRequestService->getAll($filters, $perPage, $sortBy, $sortDirection, ['project', 'user', 'files']);
        return new SiteRequestCollection($siteRequests);
    }

    public function store(StoreSiteRequestRequest $request): JsonResponse
    {
        try {
            $dto = $request->toDto();
            $siteRequest = $this->siteRequestService->create($dto);
            return response()->json(
                [
                    'success' => true, 
                    'message' => 'Заявка успешно создана.',
                    'data' => new SiteRequestResource($siteRequest) // Сервис уже загружает нужные связи
                ],
                Response::HTTP_CREATED
            );
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            // Логирование общей ошибки
            Log::error("Error creating site request: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => 'Произошла ошибка при создании заявки.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function show(SiteRequest $siteRequest): SiteRequestResource // Route model binding
    {
        // Проверка, что заявка принадлежит текущей организации и текущему пользователю (прорабу)
        // или пользователь имеет право на просмотр (например, менеджер проекта)
        if ($siteRequest->organization_id !== Auth::user()->current_organization_id) {
            abort(404, 'Заявка не найдена.');
        }
        // if ($siteRequest->user_id !== Auth::id() && !Auth::user()->can('view_all_site_requests_in_project')) {
        //     abort(403, 'Доступ запрещен.');
        // }
        return new SiteRequestResource($siteRequest->load(['project', 'user', 'files']));
    }

    public function update(UpdateSiteRequestRequest $request, SiteRequest $siteRequest): JsonResponse
    {
        // Авторизация уже выполнена в UpdateSiteRequestRequest (проверяет, что юзер - автор заявки или имеет права)
        try {
            $dto = $request->toDto();
            $updatedSiteRequest = $this->siteRequestService->update($siteRequest->id, $dto);
            return response()->json(
                [
                    'success' => true, 
                    'message' => 'Заявка успешно обновлена.',
                    'data' => new SiteRequestResource($updatedSiteRequest) // Сервис уже загружает связи
                ]
            );
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            Log::error("Error updating site request {$siteRequest->id}: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => 'Произошла ошибка при обновлении заявки.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function destroy(SiteRequest $siteRequest): JsonResponse
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = Auth::user();

        if ($siteRequest->organization_id !== $currentUser->current_organization_id || 
            ($siteRequest->user_id !== $currentUser->id && !$currentUser->can('manage_site_requests'))) {
            abort(403, 'Это действие не авторизовано.');
        }

        try {
            $this->siteRequestService->delete($siteRequest->id, $siteRequest->organization_id);
            return response()->json(null, Response::HTTP_NO_CONTENT);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            Log::error("Error deleting site request {$siteRequest->id}: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => 'Произошла ошибка при удалении заявки.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    
    // Эндпоинт для удаления конкретного файла из заявки
    public function deleteAttachment(SiteRequest $siteRequest, File $file): JsonResponse
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = Auth::user();

        if ($siteRequest->organization_id !== $currentUser->current_organization_id ||
            ($siteRequest->user_id !== $currentUser->id && !$currentUser->can('manage_site_request_attachments'))) {
            abort(403, 'Это действие не авторизовано.');
        }
        
        // Дополнительная проверка, что файл действительно принадлежит этой заявке
        if (!$siteRequest->files()->where('id', $file->id)->exists()) {
            abort(404, 'Файл не найден у данной заявки.');
        }

        try {
            $this->siteRequestService->deleteFile($siteRequest->id, $file->id, $siteRequest->organization_id);
            return response()->json(['success' => true, 'message' => 'Файл успешно удален.'], Response::HTTP_OK);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            Log::error("Error deleting attachment {$file->id} from site request {$siteRequest->id}: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Произошла ошибка при удалении файла.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
} 