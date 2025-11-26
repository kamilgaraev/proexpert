<?php

namespace App\BusinessModules\Features\SiteRequests\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestTemplateService;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestTemplateResource;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Контроллер шаблонов заявок
 */
class SiteRequestTemplateController extends Controller
{
    public function __construct(
        private readonly SiteRequestTemplateService $templateService
    ) {}

    /**
     * Список шаблонов
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $perPage = min($request->input('per_page', 15), 100);

            $filters = $request->only([
                'request_type',
                'is_active',
                'user_id',
                'search',
            ]);

            $templates = $this->templateService->paginate($organizationId, $perPage, $filters);

            return response()->json([
                'success' => true,
                'data' => SiteRequestTemplateResource::collection($templates),
                'meta' => [
                    'current_page' => $templates->currentPage(),
                    'per_page' => $templates->perPage(),
                    'total' => $templates->total(),
                    'last_page' => $templates->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('site_requests.templates.index.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить шаблоны',
            ], 500);
        }
    }

    /**
     * Популярные шаблоны
     */
    public function popular(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $limit = min($request->input('limit', 10), 20);

            $templates = $this->templateService->getPopularTemplates($organizationId, $limit);

            return response()->json([
                'success' => true,
                'data' => SiteRequestTemplateResource::collection($templates),
            ]);
        } catch (\Exception $e) {
            \Log::error('site_requests.templates.popular.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить популярные шаблоны',
            ], 500);
        }
    }

    /**
     * Показать шаблон
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $template = $this->templateService->find($id, $organizationId);

            if (!$template) {
                return response()->json([
                    'success' => false,
                    'error' => 'Шаблон не найден',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => new SiteRequestTemplateResource($template),
            ]);
        } catch (\Exception $e) {
            \Log::error('site_requests.templates.show.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить шаблон',
            ], 500);
        }
    }

    /**
     * Создать шаблон
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $userId = auth()->id();

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'request_type' => ['required', 'string'],
                'template_data' => ['required', 'array'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            $template = $this->templateService->create($organizationId, $userId, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Шаблон успешно создан',
                'data' => new SiteRequestTemplateResource($template),
            ], 201);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('site_requests.templates.store.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось создать шаблон',
            ], 500);
        }
    }

    /**
     * Обновить шаблон
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $template = $this->templateService->find($id, $organizationId);

            if (!$template) {
                return response()->json([
                    'success' => false,
                    'error' => 'Шаблон не найден',
                ], 404);
            }

            $validated = $request->validate([
                'name' => ['sometimes', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'template_data' => ['sometimes', 'array'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            $updated = $this->templateService->update($template, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Шаблон успешно обновлен',
                'data' => new SiteRequestTemplateResource($updated),
            ]);
        } catch (\Exception $e) {
            \Log::error('site_requests.templates.update.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось обновить шаблон',
            ], 500);
        }
    }

    /**
     * Удалить шаблон
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $template = $this->templateService->find($id, $organizationId);

            if (!$template) {
                return response()->json([
                    'success' => false,
                    'error' => 'Шаблон не найден',
                ], 404);
            }

            $this->templateService->delete($template);

            return response()->json([
                'success' => true,
                'message' => 'Шаблон успешно удален',
            ]);
        } catch (\Exception $e) {
            \Log::error('site_requests.templates.destroy.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось удалить шаблон',
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
                $validated['project_id'],
                $request->except(['project_id'])
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
            \Log::error('site_requests.templates.create_from.error', [
                'template_id' => $templateId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось создать заявку из шаблона',
            ], 500);
        }
    }
}

