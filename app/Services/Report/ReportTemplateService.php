<?php

namespace App\Services\Report;

use App\Models\ReportTemplate;
use App\Repositories\Interfaces\ReportTemplateRepositoryInterface;
use App\Exceptions\BusinessLogicException;
use Illuminate\Http\Request;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ReportTemplateService
{
    protected ReportTemplateRepositoryInterface $reportTemplateRepository;

    public function __construct(ReportTemplateRepositoryInterface $reportTemplateRepository)
    {
        $this->reportTemplateRepository = $reportTemplateRepository;
    }

    protected function getCurrentOrgId(Request $request): int
    {
        $organizationId = $request->user()->current_organization_id;
        if (!$organizationId) {
            throw new BusinessLogicException('Контекст организации не определен.', 400);
        }
        return $organizationId;
    }

    public function getTemplates(Request $request): LengthAwarePaginator
    {
        $organizationId = $this->getCurrentOrgId($request);
        $perPage = (int)$request->query('per_page', 15);
        return $this->reportTemplateRepository->getPaginatedTemplatesForOrganization($organizationId, $request, $perPage);
    }

    public function findTemplateById(int $templateId, Request $request): ?ReportTemplate
    {
        $organizationId = $this->getCurrentOrgId($request);
        $template = $this->reportTemplateRepository->findByIdForOrganization($templateId, $organizationId);
        if (!$template) {
            throw new BusinessLogicException('Шаблон отчета не найден.', 404);
        }
        return $template;
    }

    public function createTemplate(array $data, Request $request): ReportTemplate
    {
        $organizationId = $this->getCurrentOrgId($request);
        $userId = Auth::id();

        $data['organization_id'] = $organizationId;
        $data['user_id'] = $userId; // Или null, если шаблоны могут быть системными без user_id

        // Валидация columns_config (базовая)
        if (empty($data['columns_config']) || !is_array($data['columns_config'])) {
            throw new BusinessLogicException('Конфигурация колонок должна быть непустым массивом.', 422);
        }
        foreach ($data['columns_config'] as $column) {
            if (!isset($column['header']) || !isset($column['data_key']) || !isset($column['order'])) {
                throw new BusinessLogicException('Каждая колонка должна содержать header, data_key и order.', 422);
            }
        }
        
        $template = $this->reportTemplateRepository->create($data);
        if (!$template) {
             throw new BusinessLogicException('Не удалось создать шаблон отчета.', 500);
        }
        
        if ($template->is_default) {
            $this->reportTemplateRepository->setDefault($template);
        }
        return $template;
    }

    public function updateTemplate(int $templateId, array $data, Request $request): ReportTemplate
    {
        $organizationId = $this->getCurrentOrgId($request);
        $template = $this->reportTemplateRepository->findByIdForOrganization($templateId, $organizationId);

        if (!$template) {
            throw new BusinessLogicException('Шаблон отчета не найден.', 404);
        }

        // Валидация columns_config (если передано)
        if (isset($data['columns_config'])) {
            if (empty($data['columns_config']) || !is_array($data['columns_config'])) {
                throw new BusinessLogicException('Конфигурация колонок должна быть непустым массивом.', 422);
            }
            foreach ($data['columns_config'] as $column) {
                if (!isset($column['header']) || !isset($column['data_key']) || !isset($column['order'])) {
                    throw new BusinessLogicException('Каждая колонка должна содержать header, data_key и order.', 422);
                }
            }
        }

        if (!$this->reportTemplateRepository->update($templateId, $data)) {
            throw new BusinessLogicException('Не удалось обновить шаблон отчета.', 500);
        }
        
        $updatedTemplate = $this->reportTemplateRepository->findByIdForOrganization($templateId, $organizationId);
        if ($updatedTemplate->is_default) {
            $this->reportTemplateRepository->setDefault($updatedTemplate);
        }
        
        return $updatedTemplate;
    }

    public function deleteTemplate(int $templateId, Request $request): bool
    {
        $organizationId = $this->getCurrentOrgId($request);
        $template = $this->reportTemplateRepository->findByIdForOrganization($templateId, $organizationId);

        if (!$template) {
            throw new BusinessLogicException('Шаблон отчета не найден.', 404);
        }
        return $this->reportTemplateRepository->deleteById($templateId);
    }
    
    public function setAsDefault(int $templateId, Request $request): ReportTemplate
    {
        $organizationId = $this->getCurrentOrgId($request);
        $template = $this->reportTemplateRepository->findByIdForOrganization($templateId, $organizationId);
        if (!$template) {
            throw new BusinessLogicException('Шаблон отчета не найден.', 404);
        }
        return $this->reportTemplateRepository->setDefault($template);
    }
    
    public function getTemplateForReport(string $reportType, Request $request, ?int $templateId = null): ?ReportTemplate
    {
        $organizationId = $this->getCurrentOrgId($request);
        if ($templateId) {
            return $this->reportTemplateRepository->findByIdForOrganization($templateId, $organizationId);
        }
        return $this->reportTemplateRepository->findDefaultTemplate($reportType, $organizationId);
    }
} 