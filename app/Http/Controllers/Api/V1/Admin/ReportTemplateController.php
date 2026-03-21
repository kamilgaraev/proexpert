<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ReportTemplate\StoreReportTemplateRequest;
use App\Http\Requests\Api\V1\Admin\ReportTemplate\UpdateReportTemplateRequest;
use App\Http\Resources\Api\V1\Admin\ReportTemplateResource;
use App\Http\Responses\AdminResponse;
use App\Services\Report\ReportTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use function trans_message;

class ReportTemplateController extends Controller
{
    public function __construct(
        private readonly ReportTemplateService $reportTemplateService
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $templates = $this->reportTemplateService->getTemplates($request);

            return AdminResponse::success(
                ReportTemplateResource::collection($templates->getCollection()),
                trans_message('report_templates.loaded')
            );
        } catch (BusinessLogicException $e) {
            Log::error('report_templates.index.business_error', [
                'user_id' => $request->user()?->id,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                $e->getCode() === 404 ? trans_message('report_templates.not_found') : trans_message('report_templates.load_failed'),
                $e->getCode() >= 400 ? $e->getCode() : 400
            );
        } catch (\Throwable $e) {
            Log::error('report_templates.index.error', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('report_templates.load_failed'), 500);
        }
    }

    public function store(StoreReportTemplateRequest $request): JsonResponse
    {
        try {
            $template = $this->reportTemplateService->createTemplate($request->validated(), $request);

            return AdminResponse::success(
                new ReportTemplateResource($template),
                trans_message('report_templates.created'),
                201
            );
        } catch (BusinessLogicException $e) {
            Log::error('report_templates.store.business_error', [
                'user_id' => $request->user()?->id,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
                'payload' => $request->validated(),
            ]);

            return AdminResponse::error(
                $e->getCode() === 422 ? $e->getMessage() : trans_message('report_templates.create_failed'),
                $e->getCode() >= 400 ? $e->getCode() : 400
            );
        } catch (\Throwable $e) {
            Log::error('report_templates.store.error', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
                'payload' => $request->validated(),
            ]);

            return AdminResponse::error(trans_message('report_templates.create_failed'), 500);
        }
    }

    public function show(int $templateId, Request $request): JsonResponse
    {
        try {
            $template = $this->reportTemplateService->findTemplateById($templateId, $request);

            return AdminResponse::success(
                new ReportTemplateResource($template),
                trans_message('report_templates.loaded')
            );
        } catch (BusinessLogicException $e) {
            Log::error('report_templates.show.business_error', [
                'user_id' => $request->user()?->id,
                'template_id' => $templateId,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                $e->getCode() === 404 ? trans_message('report_templates.not_found') : trans_message('report_templates.load_failed'),
                $e->getCode() >= 400 ? $e->getCode() : 400
            );
        } catch (\Throwable $e) {
            Log::error('report_templates.show.error', [
                'user_id' => $request->user()?->id,
                'template_id' => $templateId,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('report_templates.load_failed'), 500);
        }
    }

    public function update(UpdateReportTemplateRequest $request, int $templateId): JsonResponse
    {
        try {
            $template = $this->reportTemplateService->updateTemplate($templateId, $request->validated(), $request);

            return AdminResponse::success(
                new ReportTemplateResource($template),
                trans_message('report_templates.updated')
            );
        } catch (BusinessLogicException $e) {
            Log::error('report_templates.update.business_error', [
                'user_id' => $request->user()?->id,
                'template_id' => $templateId,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
                'payload' => $request->validated(),
            ]);

            return AdminResponse::error(
                $e->getCode() === 404
                    ? trans_message('report_templates.not_found')
                    : ($e->getCode() === 422 ? $e->getMessage() : trans_message('report_templates.update_failed')),
                $e->getCode() >= 400 ? $e->getCode() : 400
            );
        } catch (\Throwable $e) {
            Log::error('report_templates.update.error', [
                'user_id' => $request->user()?->id,
                'template_id' => $templateId,
                'message' => $e->getMessage(),
                'payload' => $request->validated(),
            ]);

            return AdminResponse::error(trans_message('report_templates.update_failed'), 500);
        }
    }

    public function destroy(int $templateId, Request $request): JsonResponse
    {
        try {
            $this->reportTemplateService->deleteTemplate($templateId, $request);

            return AdminResponse::success(null, trans_message('report_templates.deleted'));
        } catch (BusinessLogicException $e) {
            Log::error('report_templates.destroy.business_error', [
                'user_id' => $request->user()?->id,
                'template_id' => $templateId,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                $e->getCode() === 404 ? trans_message('report_templates.not_found') : trans_message('report_templates.delete_failed'),
                $e->getCode() >= 400 ? $e->getCode() : 400
            );
        } catch (\Throwable $e) {
            Log::error('report_templates.destroy.error', [
                'user_id' => $request->user()?->id,
                'template_id' => $templateId,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('report_templates.delete_failed'), 500);
        }
    }

    public function setDefault(int $templateId, Request $request): JsonResponse
    {
        try {
            $template = $this->reportTemplateService->setAsDefault($templateId, $request);

            return AdminResponse::success(
                new ReportTemplateResource($template),
                trans_message('report_templates.default_set')
            );
        } catch (BusinessLogicException $e) {
            Log::error('report_templates.set_default.business_error', [
                'user_id' => $request->user()?->id,
                'template_id' => $templateId,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                $e->getCode() === 404 ? trans_message('report_templates.not_found') : trans_message('report_templates.default_failed'),
                $e->getCode() >= 400 ? $e->getCode() : 400
            );
        } catch (\Throwable $e) {
            Log::error('report_templates.set_default.error', [
                'user_id' => $request->user()?->id,
                'template_id' => $templateId,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('report_templates.default_failed'), 500);
        }
    }
}
