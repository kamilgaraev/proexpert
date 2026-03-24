<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SiteRequests\Http\Controllers;

use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestResource;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestTemplateResource;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestTemplateService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use function trans_message;

class SiteRequestTemplateController extends Controller
{
    public function __construct(
        private readonly SiteRequestTemplateService $templateService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $perPage = min((int) $request->input('per_page', 15), 100);
            $filters = $request->only(['request_type', 'is_active', 'user_id', 'search']);

            $templates = $this->templateService->paginate($organizationId, $perPage, $filters);
            $payload = SiteRequestTemplateResource::collection($templates)->response()->getData(true);

            return AdminResponse::paginated(
                $payload['data'] ?? [],
                $payload['meta'] ?? [],
                null,
                Response::HTTP_OK,
                null,
                $payload['links'] ?? null
            );
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'index',
                $e,
                $request,
                trans_message('site_requests.templates_load_error')
            );
        }
    }

    public function popular(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $limit = min((int) $request->input('limit', 10), 20);

            return AdminResponse::success(
                SiteRequestTemplateResource::collection(
                    $this->templateService->getPopularTemplates($organizationId, $limit)
                )
            );
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'popular',
                $e,
                $request,
                trans_message('site_requests.popular_templates_load_error')
            );
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $template = $this->templateService->find($id, $organizationId);

            if (!$template) {
                return AdminResponse::error(
                    trans_message('site_requests.template_not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            return AdminResponse::success(new SiteRequestTemplateResource($template));
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'show',
                $e,
                $request,
                trans_message('site_requests.template_load_error'),
                ['template_id' => $id]
            );
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'request_type' => ['required', 'string'],
                'template_data' => ['required', 'array'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            $template = $this->templateService->create(
                $organizationId,
                (int) $request->user()->id,
                $validated
            );

            return AdminResponse::success(
                new SiteRequestTemplateResource($template),
                trans_message('site_requests.template_created'),
                Response::HTTP_CREATED
            );
        } catch (ValidationException $e) {
            return AdminResponse::error(
                trans_message('site_requests.validation_error'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $e->errors()
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'store',
                $e,
                $request,
                trans_message('site_requests.template_create_error')
            );
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $template = $this->templateService->find($id, $organizationId);

            if (!$template) {
                return AdminResponse::error(
                    trans_message('site_requests.template_not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            $validated = $request->validate([
                'name' => ['sometimes', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'template_data' => ['sometimes', 'array'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            return AdminResponse::success(
                new SiteRequestTemplateResource($this->templateService->update($template, $validated)),
                trans_message('site_requests.template_updated')
            );
        } catch (ValidationException $e) {
            return AdminResponse::error(
                trans_message('site_requests.validation_error'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $e->errors()
            );
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'update',
                $e,
                $request,
                trans_message('site_requests.template_update_error'),
                ['template_id' => $id]
            );
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $template = $this->templateService->find($id, $organizationId);

            if (!$template) {
                return AdminResponse::error(
                    trans_message('site_requests.template_not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            $this->templateService->delete($template);

            return AdminResponse::success(null, trans_message('site_requests.template_deleted'));
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'destroy',
                $e,
                $request,
                trans_message('site_requests.template_delete_error'),
                ['template_id' => $id]
            );
        }
    }

    public function createFromTemplate(Request $request, int $templateId): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validate([
                'project_id' => ['required', 'integer', 'exists:projects,id'],
            ]);

            $siteRequest = $this->templateService->createFromTemplate(
                $templateId,
                $organizationId,
                (int) $request->user()->id,
                (int) $validated['project_id'],
                $request->except(['project_id'])
            );

            return AdminResponse::success(
                new SiteRequestResource($siteRequest),
                trans_message('site_requests.request_created_from_template'),
                Response::HTTP_CREATED
            );
        } catch (ValidationException $e) {
            return AdminResponse::error(
                trans_message('site_requests.validation_error'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $e->errors()
            );
        } catch (\InvalidArgumentException|\DomainException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'createFromTemplate',
                $e,
                $request,
                trans_message('site_requests.request_create_from_template_error'),
                ['template_id' => $templateId]
            );
        }
    }

    private function handleUnexpectedError(
        string $action,
        \Throwable $e,
        Request $request,
        string $message,
        array $context = []
    ): JsonResponse {
        Log::error("[SiteRequestTemplateController.{$action}] Unexpected error", [
            'message' => $e->getMessage(),
            'organization_id' => $request->attributes->get('current_organization_id'),
            'user_id' => $request->user()?->id,
            ...$context,
        ]);

        return AdminResponse::error($message, Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
