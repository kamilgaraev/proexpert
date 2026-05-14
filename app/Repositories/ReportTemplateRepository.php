<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ReportTemplate;
use App\Repositories\Interfaces\ReportTemplateRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportTemplateRepository extends BaseRepository implements ReportTemplateRepositoryInterface
{
    private const ALLOWED_SORTS = [
        'name' => 'name',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
        'report_type' => 'report_type',
        'is_default' => 'is_default',
    ];

    public function __construct()
    {
        parent::__construct(ReportTemplate::class);
    }

    public function getPaginatedTemplatesForOrganization(int $organizationId, Request $request, int $perPage): LengthAwarePaginator
    {
        $query = $this->model
            ->where(function ($query) use ($organizationId): void {
                $query->where('organization_id', $organizationId)
                    ->orWhereNull('organization_id');
            });

        if ($request->filled('report_type')) {
            $query->where('report_type', $request->input('report_type'));
        }

        if ($request->filled('name')) {
            $query->where('name', 'like', '%' . $request->input('name') . '%');
        }

        $sortBy = $this->normalizeSortBy($request->input('sort_by'));
        $sortDirection = $this->normalizeSortDirection($request->input('sort_direction'));

        return $query
            ->orderBy($sortBy, $sortDirection)
            ->paginate($perPage);
    }

    public function findByIdForOrganization(int $templateId, int $organizationId): ?ReportTemplate
    {
        return $this->model
            ->where('id', $templateId)
            ->where(function ($query) use ($organizationId): void {
                $query->where('organization_id', $organizationId)
                    ->orWhereNull('organization_id');
            })
            ->first();
    }

    public function findDefaultTemplate(string $reportType, int $organizationId): ?ReportTemplate
    {
        $template = $this->model
            ->where('report_type', $reportType)
            ->where('organization_id', $organizationId)
            ->where('is_default', true)
            ->first();

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
        return DB::transaction(function () use ($template): ReportTemplate {
            $this->model
                ->where('organization_id', $template->organization_id)
                ->where('report_type', $template->report_type)
                ->where('id', '!=', $template->id)
                ->update(['is_default' => false]);

            $template->is_default = true;
            $template->save();

            return $template;
        });
    }

    public function deleteById(int $templateId): bool
    {
        $template = $this->model->find($templateId);

        return $template ? (bool) $template->delete() : false;
    }

    private function normalizeSortBy(mixed $value): string
    {
        $sortBy = is_string($value) ? strtolower($value) : 'name';

        return self::ALLOWED_SORTS[$sortBy] ?? 'name';
    }

    private function normalizeSortDirection(mixed $value): string
    {
        $sortDirection = is_string($value) ? strtolower($value) : 'asc';

        return in_array($sortDirection, ['asc', 'desc'], true) ? $sortDirection : 'asc';
    }
}
