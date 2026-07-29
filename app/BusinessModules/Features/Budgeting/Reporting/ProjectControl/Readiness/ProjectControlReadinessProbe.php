<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceReadiness;
use App\BusinessModules\Features\Budgeting\Models\WipForecastVersion;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Models\ProjectControlBaselineVersion;
use App\Support\Reporting\ReportSourceReadinessFactory;

final readonly class ProjectControlReadinessProbe implements ReportSourceReadinessProbe
{
    public function __construct(
        private ReportSourceReadinessFactory $readiness,
    ) {
    }

    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === 'project_evm_control'
            && $definition->formulaVersion === 'project_control_core.v1';
    }

    public function reportCodes(): array
    {
        return ['project_evm_control'];
    }

    public function inspect(
        ReportExecutionContext $context,
        ReportQuery $query,
    ): ReportSourceReadiness {
        $eligible = [];
        $projected = [];
        $gapCount = 0;
        foreach ($context->scope->projectIds as $projectId) {
            $eligible[] = ['kind' => 'approved_baseline', 'project_id' => $projectId];
            $baseline = ProjectControlBaselineVersion::query()
                ->where('organization_id', $context->scope->organizationId)
                ->where('project_id', $projectId)
                ->where('approved_at', '<=', $query->asOf)
                ->orderByDesc('approved_at')
                ->orderByDesc('version_number')
                ->first();
            if ($baseline === null) {
                $gapCount++;
            } else {
                $projected[] = [
                    'kind' => 'approved_baseline',
                    'project_id' => $projectId,
                    'source_hash' => (string) $baseline->source_hash,
                ];
            }

            $eligible[] = ['kind' => 'approved_wip', 'project_id' => $projectId];
            $wip = WipForecastVersion::query()
                ->where('organization_id', $context->scope->organizationId)
                ->where('project_id', $projectId)
                ->whereDate('as_of_date', '<=', $query->asOf->format('Y-m-d'))
                ->where(function ($builder) use ($query): void {
                    $builder
                        ->where(function ($approved) use ($query): void {
                            $approved
                                ->where('status', 'approved')
                                ->whereNotNull('approved_at')
                                ->where('approved_at', '<=', $query->asOf);
                        })
                        ->orWhere(function ($active) use ($query): void {
                            $active
                                ->where('status', 'active')
                                ->whereNotNull('activated_at')
                                ->where('activated_at', '<=', $query->asOf);
                        });
                })
                ->whereNotNull('source_snapshot_hash')
                ->whereHas('lines')
                ->orderByDesc('as_of_date')
                ->orderByDesc('version_number')
                ->first();
            if ($wip === null || trim((string) $wip->source_snapshot_hash) === '') {
                $gapCount++;
            } else {
                $projected[] = [
                    'kind' => 'approved_wip',
                    'project_id' => $projectId,
                    'source_hash' => (string) $wip->source_snapshot_hash,
                ];
            }
        }

        $watermark = 'project-control:'.hash(
            'sha256',
            implode(':', array_column($projected, 'source_hash')),
        );

        return $this->readiness->make($eligible, $projected, $gapCount, 0, $watermark);
    }
}
