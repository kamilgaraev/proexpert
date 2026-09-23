<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Models\ProjectControlRow;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Models\ProjectControlSnapshot;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

use function trans_message;

final class MobileBudgetingService
{
    private const MODULE = 'budgeting';
    private const VIEW_PERMISSION = 'reports.project_control.view';

    public function __construct(
        private readonly AccessController $moduleAccess,
        private readonly AuthorizationService $authorization,
        private readonly MobileProjectAccessResolver $projectAccess,
    ) {}

    /** @return array<string, mixed> */
    public function summary(User $actor, int $organizationId, int $projectId): array
    {
        $project = $this->accessibleProject($actor, $organizationId, $projectId);
        $this->assertCanView($actor, $organizationId, $projectId);
        $snapshot = $this->latestSnapshot($organizationId, $projectId);

        return [
            'project' => [
                'id' => (int) $project->id,
                'name' => (string) $project->name,
            ],
            'snapshot' => $snapshot === null ? null : [
                'id' => (string) $snapshot->id,
                'status_date' => $snapshot->status_date?->toDateString(),
                'generated_at' => $snapshot->generated_at?->toIso8601String(),
                'stale_at' => $snapshot->stale_at?->toIso8601String(),
                'is_stale' => $snapshot->stale_at !== null && $snapshot->stale_at->isPast(),
                'row_count' => (int) $snapshot->row_count,
            ],
            'totals_by_currency' => $snapshot === null ? [] : $this->safeTotals($snapshot),
        ];
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function executionCards(
        User $actor,
        int $organizationId,
        int $projectId,
        int $page,
        int $perPage,
    ): LengthAwarePaginator {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), 100);
        $this->accessibleProject($actor, $organizationId, $projectId);
        $this->assertCanView($actor, $organizationId, $projectId);
        $snapshot = $this->latestSnapshot($organizationId, $projectId);

        if ($snapshot === null) {
            return new LengthAwarePaginator([], 0, $perPage, $page);
        }

        $cards = ProjectControlRow::query()
            ->where('organization_id', $organizationId)
            ->where('snapshot_id', $snapshot->id)
            ->where('project_id', $projectId)
            ->orderBy('wbs_code')
            ->orderBy('task_id')
            ->orderBy('currency')
            ->orderBy('id')
            ->paginate($perPage, [
                'row_key', 'wbs_code', 'task_id', 'currency', 'bac_minor', 'pv_minor', 'ev_minor', 'sv_minor', 'spi',
            ], 'page', $page);

        $cards->setCollection($cards->getCollection()->map(static fn (ProjectControlRow $row): array => [
            'row_key' => (string) $row->row_key,
            'wbs_code' => $row->wbs_code,
            'task_id' => (int) $row->task_id,
            'currency' => (string) $row->currency,
            'bac_minor' => (int) $row->bac_minor,
            'pv_minor' => (int) $row->pv_minor,
            'ev_minor' => (int) $row->ev_minor,
            'sv_minor' => (int) $row->sv_minor,
            'spi' => $row->spi === null ? null : (string) $row->spi,
        ]));

        return $cards;
    }

    private function accessibleProject(User $actor, int $organizationId, int $projectId): Project
    {
        try {
            return $this->projectAccess->resolve(
                $actor,
                $organizationId,
                $projectId,
                trans_message('mobile_companions.errors.item_not_found'),
            );
        } catch (DomainException) {
            throw (new ModelNotFoundException())->setModel(Project::class, [$projectId]);
        }
    }

    private function assertCanView(User $actor, int $organizationId, int $projectId): void
    {
        if ($organizationId <= 0
            || (int) $actor->current_organization_id !== $organizationId
            || ! $this->moduleAccess->hasModuleAccess($organizationId, self::MODULE)
            || ! $this->authorization->can($actor, self::VIEW_PERMISSION, [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'strict_project_scope' => true,
            ])) {
            throw new AuthorizationException(trans_message('mobile_companions.errors.permission_denied'));
        }
    }

    private function latestSnapshot(int $organizationId, int $projectId): ?ProjectControlSnapshot
    {
        return ProjectControlSnapshot::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->orderByDesc('status_date')
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->first();
    }

    /** @return list<array<string, int|float|string|null>> */
    private function safeTotals(ProjectControlSnapshot $snapshot): array
    {
        $currencies = $snapshot->totals['currencies'] ?? [];
        if (! is_array($currencies)) {
            return [];
        }

        $totals = [];
        foreach ($currencies as $currency => $metrics) {
            if (! is_string($currency) || ! is_array($metrics)) {
                continue;
            }

            $totals[] = [
                'currency' => $currency,
                'bac_minor' => (int) ($metrics['bac_minor'] ?? 0),
                'pv_minor' => (int) ($metrics['pv_minor'] ?? 0),
                'ev_minor' => (int) ($metrics['ev_minor'] ?? 0),
                'sv_minor' => (int) ($metrics['sv_minor'] ?? 0),
                'spi' => isset($metrics['spi']) ? (string) $metrics['spi'] : null,
            ];
        }

        return $totals;
    }
}
