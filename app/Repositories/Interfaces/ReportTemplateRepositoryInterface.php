<?php

namespace App\Repositories\Interfaces;

use App\Models\ReportTemplate;
use Illuminate\Http\Request;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ReportTemplateRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Получить пагинированный список шаблонов отчетов для организации.
     *
     * @param int $organizationId
     * @param Request $request // Для фильтров и сортировки
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getPaginatedTemplatesForOrganization(int $organizationId, Request $request, int $perPage): LengthAwarePaginator;

    /**
     * Найти шаблон по ID в указанной организации.
     *
     * @param int $templateId
     * @param int $organizationId
     * @return ReportTemplate|null
     */
    public function findByIdForOrganization(int $templateId, int $organizationId): ?ReportTemplate;
    
    /**
     * Найти дефолтный шаблон для типа отчета в организации.
     *
     * @param string $reportType
     * @param int $organizationId
     * @return ReportTemplate|null
     */
    public function findDefaultTemplate(string $reportType, int $organizationId): ?ReportTemplate;
    
    /**
     * Установить шаблон как дефолтный, сбрасывая флаг у других для этого типа отчета и организации.
     *
     * @param ReportTemplate $template
     * @return ReportTemplate
     */
    public function setDefault(ReportTemplate $template): ReportTemplate;
} 