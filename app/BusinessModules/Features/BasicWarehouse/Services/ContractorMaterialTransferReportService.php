<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\DTO\ContractorMaterialTransferRow;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Queries\ContractorMaterialTransferQuery;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contractor;
use App\Models\Material;
use App\Models\Project;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContractorMaterialTransferReportService
{
    private const EXPORT_LIMIT = 5000;

    public function __construct(
        private readonly ContractorMaterialTransferQuery $query,
        private readonly AuthorizationService $authorization,
        private readonly FileService $files,
    ) {}

    public function catalog(User $actor): array
    {
        $this->authorize($actor, []);

        return ['definitions' => [['code' => 'contractor_material_transfers']]];
    }

    public function report(User $actor, array $filters): array
    {
        $organizationId = $this->authorize($actor, $filters);

        // Итоги и страница читаются из одного согласованного состояния PostgreSQL.
        return DB::transaction(function () use ($organizationId, $filters): array {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $page = $this->query->build($organizationId, $filters)
                ->with(['material' => static fn ($scope) => $scope->withTrashed(), 'material.measurementUnit', 'warehouse' => static fn ($scope) => $scope->withTrashed(), 'project'])
                ->orderByDesc('movement_date')->orderByDesc('id')
                ->paginate(perPage: min(100, max(1, (int) ($filters['per_page'] ?? 50))), page: max(1, (int) ($filters['page'] ?? 1)));

            return [
                'rows' => $this->mapRows($organizationId, $page->getCollection()),
                'summary' => $this->query->summary($organizationId, $filters),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'per_page' => $page->perPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                ],
            ];
        });
    }

    public function export(User $actor, array $filters): array
    {
        $organizationId = $this->authorize($actor, $filters, true);
        $movements = $this->query->build($organizationId, $filters)
            ->with(['material' => static fn ($scope) => $scope->withTrashed(), 'material.measurementUnit', 'warehouse' => static fn ($scope) => $scope->withTrashed(), 'project'])
            ->orderByDesc('movement_date')->orderByDesc('id')->limit(self::EXPORT_LIMIT + 1)->get();
        if ($movements->count() > self::EXPORT_LIMIT) {
            throw ValidationException::withMessages([
                'export' => trans_message('warehouse_basic.tolling_report.export_limit', ['limit' => self::EXPORT_LIMIT]),
            ]);
        }
        $content = $this->csv($this->mapRows($organizationId, $movements));
        $path = 'org-'.$organizationId.'/warehouse/exports/user-'.$actor->id.'/contractor-materials/'.Str::uuid().'.csv';
        $stored = $this->files->putPrivate($path, $content, 'text/csv; charset=utf-8', hash('sha256', $content));

        return [
            'url' => $this->files->temporaryDownloadUrl($stored->key, 900),
            'filename' => 'davalcheskie-materialy-'.now()->format('Y-m-d').'.csv',
        ];
    }

    private function authorize(User $actor, array $filters, bool $export = false): int
    {
        $organizationId = (int) $actor->current_organization_id;
        $context = ['organization_id' => $organizationId];
        if ($organizationId < 1 || ! $this->authorization->canCurrent($actor, 'warehouse.reports', $context)
            || ($export && ! $this->authorization->canCurrent($actor, 'warehouse.reports.export', $context))) {
            throw new AuthorizationException;
        }

        foreach (['warehouse_id' => OrganizationWarehouse::class, 'material_id' => Material::class, 'contractor_id' => Contractor::class] as $key => $model) {
            if (isset($filters[$key]) && ! $model::withTrashed()->where('organization_id', $organizationId)->whereKey((int) $filters[$key])->exists()) {
                throw ValidationException::withMessages([$key => trans_message('warehouse_basic.tolling_report.invalid_filter')]);
            }
        }
        if (isset($filters['project_id']) && ! Project::query()->accessibleByOrganization($organizationId)->whereKey((int) $filters['project_id'])->exists()) {
            throw ValidationException::withMessages(['project_id' => trans_message('warehouse_basic.tolling_report.invalid_filter')]);
        }

        return $organizationId;
    }

    private function mapRows(int $organizationId, Collection $movements): array
    {
        $ids = $movements->map(static fn (WarehouseMovement $movement) => $movement->metadata['contractor_id'] ?? null)->filter()->unique()->all();
        $names = $ids === [] ? [] : Contractor::withTrashed()->where('organization_id', $organizationId)->whereIn('id', $ids)->pluck('name', 'id')->all();

        return $movements->map(static fn (WarehouseMovement $movement): array => ContractorMaterialTransferRow::fromMovement(
            $movement, $names[$movement->metadata['contractor_id'] ?? 0] ?? null,
        ))->all();
    }

    private function csv(array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        $columns = ['movement_date', 'contractor_name', 'material_name', 'material_code', 'measurement_unit', 'quantity', 'price', 'total_value', 'warehouse_name', 'project_name', 'document_number', 'reason'];
        fputcsv($stream, array_map(static fn (string $key) => trans_message('warehouse_basic.tolling_report.columns.'.$key), $columns), ';');
        foreach ($rows as $row) {
            fputcsv($stream, array_map(static function (string $key) use ($row): string|float|int {
                $value = $row[$key] ?? '';
                return is_string($value) && preg_match('/^\s*[=+\-@]/u', $value) === 1 ? "'".$value : $value;
            }, $columns), ';');
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $content;
    }
}
