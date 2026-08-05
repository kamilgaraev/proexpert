<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Features\Budgeting\Models\EpmDataMartSnapshot;
use App\BusinessModules\Features\Budgeting\Models\WipForecastVersion;
use App\BusinessModules\Features\Budgeting\Services\EpmDataMartPayloadProjector;

final readonly class WipCompletionForecastOptionsService
{
    public function options(ReportScope $scope): array
    {
        $projectId = count($scope->projectIds) === 1 ? $scope->projectIds[0] : null;
        if ($projectId === null) {
            return $this->unavailable('project_context_required');
        }

        $versions = WipForecastVersion::query()
            ->where('organization_id', $scope->organizationId)
            ->where('project_id', $projectId)
            ->where('status', 'active')
            ->orderByDesc('version_number')
            ->limit(2)
            ->get();
        if ($versions->count() !== 1) {
            return $this->unavailable('active_version_required');
        }

        $version = $versions->first();
        $snapshot = EpmDataMartSnapshot::query()
            ->where('organization_id', $scope->organizationId)
            ->where('project_id', $projectId)
            ->where('report_scope', 'wip_forecast')
            ->where('status', 'succeeded')
            ->where('formula_version', EpmDataMartPayloadProjector::FORMULA_VERSION)
            ->whereNull('superseded_at')
            ->where('period_start', $version->period_start)
            ->where('period_end', $version->period_end)
            ->latest('generated_at')
            ->first();
        if (! $snapshot instanceof EpmDataMartSnapshot) {
            return [
                ...$this->version($version),
                'available' => false,
                'reason' => 'source_snapshot_required',
                'period' => null,
                'currencies' => [],
            ];
        }

        return [
            ...$this->version($version),
            'available' => true,
            'reason' => null,
            'period' => [
                'from' => $snapshot->period_start?->format('Y-m-d'),
                'to' => $snapshot->period_end?->format('Y-m-d'),
                'as_of' => $snapshot->as_of_date?->format('Y-m-d'),
                'run_as_of' => $snapshot->generated_at?->toIso8601String(),
                'generated_at' => $snapshot->generated_at?->toIso8601String(),
                'stale_at' => $snapshot->stale_at?->toIso8601String(),
            ],
            'currencies' => $snapshot->currency === null ? [] : [[
                'id' => (string) $snapshot->currency,
                'name' => (string) $snapshot->currency,
            ]],
        ];
    }

    private function unavailable(string $reason): array
    {
        return [
            'available' => false,
            'reason' => $reason,
            'active_version' => null,
            'period' => null,
            'currencies' => [],
        ];
    }

    private function version(WipForecastVersion $version): array
    {
        return ['active_version' => [
            'id' => (int) $version->id,
            'uuid' => (string) $version->uuid,
            'name' => (string) $version->name,
            'number' => (int) $version->version_number,
            'status' => (string) $version->status,
        ]];
    }
}
