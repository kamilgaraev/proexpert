<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Report\ReportTemplateService;
use App\Http\Requests\Api\V1\Admin\ReportTemplate\StoreReportTemplateRequest;
use App\Http\Requests\Api\V1\Admin\ReportTemplate\UpdateReportTemplateRequest;
use App\Http\Resources\Api\V1\Admin\ReportTemplateResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Exceptions\BusinessLogicException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;

class ReportTemplateController extends Controller
{
    protected ReportTemplateService $reportTemplateService;

    public function __construct(ReportTemplateService $reportTemplateService)
    {
        $this->reportTemplateService = $reportTemplateService;
        // TODO: Добавить Gate, например, 'can:manage-report-templates'
        // $this->middleware('can:manage-report-templates');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $templates = $this->reportTemplateService->getTemplates($request);
        return ReportTemplateResource::collection($templates);
    }

    public function store(StoreReportTemplateRequest $request): ReportTemplateResource
    {
        $template = $this->reportTemplateService->createTemplate($request->validated(), $request);
        return new ReportTemplateResource($template);
    }

    public function show(int $templateId, Request $request): ReportTemplateResource
    {
        $template = $this->reportTemplateService->findTemplateById($templateId, $request);
        return new ReportTemplateResource($template);
    }

    public function update(UpdateReportTemplateRequest $request, int $templateId): ReportTemplateResource
    {
        $template = $this->reportTemplateService->updateTemplate($templateId, $request->validated(), $request);
        return new ReportTemplateResource($template);
    }

    public function destroy(int $templateId, Request $request): JsonResponse
    {
        $this->reportTemplateService->deleteTemplate($templateId, $request);
        return response()->json(['success' => true, 'message' => 'Шаблон отчета успешно удален.'], 200);
    }
    
    public function setDefault(int $templateId, Request $request): ReportTemplateResource
    {
        $template = $this->reportTemplateService->setAsDefault($templateId, $request);
        return new ReportTemplateResource($template);
    }
} 