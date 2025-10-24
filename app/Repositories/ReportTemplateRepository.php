<?php

namespace App\Repositories;

use App\Models\ReportTemplate;
use App\Repositories\Interfaces\ReportTemplateRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ReportTemplateRepository extends BaseRepository implements ReportTemplateRepositoryInterface
{
    public function __construct()
    {
        parent::__construct(ReportTemplate::class);
    }

    public function getPaginatedTemplatesForOrganization(int $organizationId, Request $request, int $perPage): LengthAwarePaginator
    {
        $query = $this->model->where('organization_id', $organizationId);

        if ($request->filled('report_type')) {
            $query->where('report_type', $request->input('report_type'));
        }

        if ($request->filled('name')) {
            $query->where('name', 'like', '%' . $request->input('name') . '%');
        }
        
        $sortBy = $request->input('sort_by', 'name');
        $sortDirection = $request->input('sort_direction', 'asc');
        $query->orderBy($sortBy, $sortDirection);

        return $query->paginate($perPage);
    }

    public function findByIdForOrganization(int $templateId, int $organizationId): ?ReportTemplate
    {
        return $this->model
            ->where('id', $templateId)
            ->where(function ($query) use ($organizationId) {
                $query->where('organization_id', $organizationId)
                      ->orWhereNull('organization_id'); // Системные шаблоны доступны всем
            })
            ->first();
    }
    
    public function findDefaultTemplate(string $reportType, int $organizationId): ?ReportTemplate
    {
        // Сначала ищем дефолтный шаблон организации
        $template = $this->model
            ->where('report_type', $reportType)
            ->where('organization_id', $organizationId)
            ->where('is_default', true)
            ->first();
        
        // Если не найден - берем системный дефолтный шаблон
        if (!$template) {
            $template = $this->model
                ->where('report_type', $reportType)
                ->whereNull('organization_id')
                ->where('is_default', true)
                ->first();
        }
        
        return $template;
    }

    public function setDefault(ReportTemplate $template): ReportTemplate
    {
        return DB::transaction(function () use ($template) {
            // Сбрасываем флаг is_default у других шаблонов этого типа для этой организации
            $this->model
                ->where('organization_id', $template->organization_id)
                ->where('report_type', $template->report_type)
                ->where('id', '!=', $template->id)
                ->update(['is_default' => false]);

            // Устанавливаем флаг для текущего шаблона
            $template->is_default = true;
            $template->save();
            return $template;
        });
    }
} 