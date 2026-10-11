<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Services\DesignModelViewerPreparationService;
use App\BusinessModules\Features\DesignManagement\Support\DesignViewerConverter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class UpgradeDesignIfcIndexCommand extends Command
{
    protected $signature = 'design-management:upgrade-ifc-index {--limit=2} {--scan-limit=100}';

    protected $description = 'Обновление устаревших индексов моделей через штатную очередь подготовки';

    public function handle(DesignModelViewerPreparationService $preparation): int
    {
        $limit = min(10, max(1, (int) $this->option('limit')));
        $scanLimit = min(500, max($limit, (int) $this->option('scan-limit')));
        $cursorKey = 'design-management:ifc-index-upgrade:cursor:'.DesignViewerConverter::IFC_INDEX_SCHEMA_VERSION;
        $cursor = max(0, (int) Cache::get($cursorKey, 0));
        $query = DesignModelDerivative::query()->where('viewer_provider', 'thatopen')
            ->where('derivative_format', 'thatopen_frag')->where('status', 'ready')
            ->whereRaw("CASE WHEN metadata->>'ifc_index_schema_version' ~ '^[0-9]+$' THEN (metadata->>'ifc_index_schema_version')::numeric ELSE 0 END < ?", [DesignViewerConverter::IFC_INDEX_SCHEMA_VERSION])
            ->whereHas('version', fn ($version) => $version->where('file_format', 'ifc')->where('source_format', 'ifc')
                ->whereColumn('design_artifact_versions.organization_id', 'design_model_derivatives.organization_id')
                ->whereColumn('design_artifact_versions.project_id', 'design_model_derivatives.project_id')
                ->whereHas('artifact.package', fn ($package) => $package
                    ->whereColumn('design_packages.organization_id', 'design_model_derivatives.organization_id')
                    ->whereColumn('design_packages.project_id', 'design_model_derivatives.project_id')));
        $candidates = (clone $query)->where('id', '>', $cursor)->orderBy('id')->limit($scanLimit)->get();
        if ($candidates->isEmpty() && $cursor > 0) {
            $candidates = $query->orderBy('id')->limit($scanLimit)->get();
            Cache::forever($cursorKey, 0);
        }
        $queued = 0;
        $scanned = 0;
        foreach ($candidates as $candidate) {
            $scanned++;
            try {
                if ($preparation->queueIndexUpgrade($candidate)) {
                    $queued++;
                }
            } catch (Throwable $exception) {
                Log::warning('design_management.ifc_index_upgrade.queue_failed', ['derivative_id' => $candidate->id, 'exception_class' => $exception::class]);
            }
            Cache::forever($cursorKey, (int) $candidate->id);
            if ($queued >= $limit) {
                break;
            }
        }
        $this->line("scanned={$scanned}; queued={$queued}; scan_limit={$scanLimit}");

        return self::SUCCESS;
    }
}
