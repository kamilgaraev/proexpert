<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeClaim;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DTO\ChangeClaimReadinessSnapshot;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Readiness\ChangeClaimReadinessProbe;
use App\Models\Contract;
use App\Models\Project;
use App\Models\User;
use InvalidArgumentException;

final readonly class ChangeClaimOptionsService
{
    public function __construct(private ChangeClaimReadinessProbe $readiness) {}

    public function options(ReportExecutionContext $context, ReportQuery $query): array
    {
        if ($context->scope->canonicalIdentity() !== $query->scope->canonicalIdentity()) {
            throw new InvalidArgumentException('change_claim_options_scope_mismatch');
        }
        $snapshot = $this->readiness->inspect($context->scope, $query);

        return [
            'availability' => self::availability($snapshot),
            'period' => ['from' => $query->filters->values['period_from'], 'to' => $query->filters->values['period_to']],
            'projects' => $this->modelOptions(Project::class, $context->scope->organizationId, $snapshot->projectIds, 'name'),
            'contracts' => $this->modelOptions(Contract::class, $context->scope->organizationId, $snapshot->contractIds, 'number'),
            'allocations' => $this->numbered($snapshot->allocationIds, 'reports.change_claim_contingency.option_allocation'),
            'changes' => $this->changeOptions($context->scope->organizationId, $snapshot->changeRequestIds),
            'claims' => $this->claimOptions($context->scope->organizationId, $snapshot->claimIds),
            'statuses' => $this->labels($snapshot->statuses, 'statuses'),
            'currencies' => array_map(static fn (string $id): array => ['id' => $id, 'name' => $id], $snapshot->currencies),
            'initiator_types' => $this->labels($snapshot->initiatorTypes, 'initiator_types'),
            'initiators' => $this->userOptions($snapshot->initiatorUserIds),
            'owners' => $this->userOptions($snapshot->ownerUserIds),
            'reasons' => array_map(static fn (string $reason): array => ['id' => $reason, 'name' => $reason], $snapshot->reasons),
            'source_types' => $this->labels($snapshot->sourceTypes, 'source_types'),
        ];
    }

    public static function availability(ChangeClaimReadinessSnapshot $snapshot): array
    {
        $status = match (true) {
            $snapshot->factCount === 0 => 'no_data',
            $snapshot->hasCheckpoint && $snapshot->historyComplete => 'available',
            default => 'source_incomplete',
        };

        return ['status' => $status, 'can_run' => $status === 'available'];
    }

    private function modelOptions(string $model, int $organizationId, array $ids, string $label): array
    {
        return $ids === [] ? [] : $model::query()->where('organization_id', $organizationId)->whereIn('id', $ids)
            ->orderBy($label)->get(['id', $label])->map(static fn ($item): array => [
                'id' => (int) $item->id,
                'name' => (string) ($item->{$label} ?: $item->id),
            ])->values()->all();
    }

    private function changeOptions(int $organizationId, array $ids): array
    {
        return $ids === [] ? [] : ChangeRequest::query()->where('organization_id', $organizationId)->whereIn('id', $ids)
            ->orderBy('change_number')->get(['id', 'change_number', 'title'])->map(static fn ($item): array => [
                'id' => (int) $item->id,
                'name' => trim((string) $item->change_number.' — '.(string) $item->title, ' —'),
            ])->values()->all();
    }

    private function claimOptions(int $organizationId, array $ids): array
    {
        return $ids === [] ? [] : ChangeClaim::query()->where('organization_id', $organizationId)->whereIn('id', $ids)
            ->orderBy('claim_number')->get(['id', 'claim_number', 'title'])->map(static fn ($item): array => [
                'id' => (int) $item->id,
                'name' => trim((string) $item->claim_number.' — '.(string) $item->title, ' —'),
            ])->values()->all();
    }

    private function userOptions(array $ids): array
    {
        return $ids === [] ? [] : User::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
            ->map(static fn ($item): array => ['id' => (int) $item->id, 'name' => (string) $item->name])->values()->all();
    }

    private function numbered(array $ids, string $key): array
    {
        return array_map(static fn (int $id): array => ['id' => $id, 'name' => trans_message($key, ['id' => $id])], $ids);
    }

    private function labels(array $ids, string $group): array
    {
        return array_map(static function (string $id) use ($group): array {
            $key = "reports.change_claim_contingency.{$group}.{$id}";
            $label = trans_message($key);

            return [
                'id' => $id,
                'name' => $label === $key
                    ? trans_message('reports.change_claim_contingency.option_other')
                    : $label,
            ];
        }, $ids);
    }
}
