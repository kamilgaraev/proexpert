<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Exceptions\BusinessLogicException;
use App\Models\ReportTemplate;
use App\Repositories\Interfaces\ReportTemplateRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use function trans_message;

class ReportTemplateService
{
    private const DEFAULT_PER_PAGE = 15;
    private const MAX_PER_PAGE = 100;

    public function __construct(
        protected ReportTemplateRepositoryInterface $reportTemplateRepository
    ) {
    }

    public function getTemplates(Request $request): LengthAwarePaginator
    {
        $organizationId = $this->getCurrentOrgId($request);
        $perPage = $this->normalizePerPage($request->query('per_page'));

        return $this->reportTemplateRepository->getPaginatedTemplatesForOrganization($organizationId, $request, $perPage);
    }

    public function findTemplateById(int $templateId, Request $request): ReportTemplate
    {
        $organizationId = $this->getCurrentOrgId($request);
        $template = $this->reportTemplateRepository->findByIdForOrganization($templateId, $organizationId);

        if (!$template) {
            throw new BusinessLogicException(trans_message('report_templates.not_found'), 404);
        }

        return $template;
    }

    public function createTemplate(array $data, Request $request): ReportTemplate
    {
        $organizationId = $this->getCurrentOrgId($request);

        $data['organization_id'] = $organizationId;
        $data['user_id'] = Auth::id();

        $this->assertValidColumnsConfig($data['columns_config'] ?? null);

        $template = $this->reportTemplateRepository->create($data);
        if (!$template) {
            throw new BusinessLogicException(trans_message('report_templates.create_failed'), 500);
        }

        if ($template->is_default) {
            $this->reportTemplateRepository->setDefault($template);
        }

        return $template;
    }

    public function updateTemplate(int $templateId, array $data, Request $request): ReportTemplate
    {
        $organizationId = $this->getCurrentOrgId($request);
        $template = $this->findMutableTemplate($templateId, $organizationId);

        if (array_key_exists('columns_config', $data)) {
            $this->assertValidColumnsConfig($data['columns_config']);
        }

        if (!$this->reportTemplateRepository->update($template->id, $data)) {
            throw new BusinessLogicException(trans_message('report_templates.update_failed'), 500);
        }

        $updatedTemplate = $this->findMutableTemplate($template->id, $organizationId);
        if ($updatedTemplate->is_default) {
            $this->reportTemplateRepository->setDefault($updatedTemplate);
        }

        return $updatedTemplate;
    }

    public function deleteTemplate(int $templateId, Request $request): bool
    {
        $organizationId = $this->getCurrentOrgId($request);
        $template = $this->findMutableTemplate($templateId, $organizationId);

        return $this->reportTemplateRepository->deleteById($template->id);
    }

    public function setAsDefault(int $templateId, Request $request): ReportTemplate
    {
        $organizationId = $this->getCurrentOrgId($request);
        $template = $this->findMutableTemplate($templateId, $organizationId);

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

    protected function getCurrentOrgId(Request $request): int
    {
        $organizationId = $request->user()?->current_organization_id;

        if (!$organizationId) {
            throw new BusinessLogicException(trans_message('report_templates.load_failed'), 400);
        }

        return (int) $organizationId;
    }

    private function findMutableTemplate(int $templateId, int $organizationId): ReportTemplate
    {
        $template = $this->reportTemplateRepository->findByIdForOrganization($templateId, $organizationId);

        if (!$template || (int) $template->organization_id !== $organizationId) {
            throw new BusinessLogicException(trans_message('report_templates.not_found'), 404);
        }

        return $template;
    }

    private function assertValidColumnsConfig(mixed $columnsConfig): void
    {
        if (!is_array($columnsConfig) || $columnsConfig === []) {
            throw new BusinessLogicException(trans_message('report_templates.invalid_columns_config'), 422);
        }

        foreach ($columnsConfig as $column) {
            if (
                !is_array($column)
                || !isset($column['header'])
                || !isset($column['data_key'])
                || !isset($column['order'])
            ) {
                throw new BusinessLogicException(trans_message('report_templates.invalid_column_definition'), 422);
            }
        }
    }

    private function normalizePerPage(mixed $value): int
    {
        $perPage = filter_var($value ?? self::DEFAULT_PER_PAGE, FILTER_VALIDATE_INT);

        if (!is_int($perPage) || $perPage <= 0) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }
}
