<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Models\ContractPerformanceAct;
use App\Models\PerformanceActLine;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceOwnerVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class ProductionAcceptanceOwnerVersionWriter
{
    public function record(
        ContractPerformanceAct $act,
        string $eventType,
        CarbonImmutable $effectiveAt,
    ): ProductionAcceptanceOwnerVersion {
        $members = $this->members($act);
        if ($members === []) {
            throw new InvalidArgumentException('production_acceptance_owner_membership_empty');
        }
        $identity = [
            'contract_id' => (int) $act->contract_id,
            'effective_at' => $effectiveAt->format(DATE_ATOM),
            'event_type' => $eventType,
            'members' => $members,
            'performance_act_id' => (int) $act->id,
            'project_id' => (int) $act->project_id,
        ];
        $sourceEventId = 'acceptance_owner_'.substr(
            hash('sha256', CanonicalJson::encode($identity)),
            0,
            44,
        );

        return DB::transaction(function () use (
            $act,
            $eventType,
            $effectiveAt,
            $members,
            $identity,
            $sourceEventId,
        ): ProductionAcceptanceOwnerVersion {
            DB::select(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                ['production-acceptance-owner:'.$act->contract->organization_id.':'.$act->id],
            );
            $existing = ProductionAcceptanceOwnerVersion::query()
                ->where('organization_id', $act->contract->organization_id)
                ->where('source_event_id', $sourceEventId)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
            $version = (int) (ProductionAcceptanceOwnerVersion::query()
                ->where('organization_id', $act->contract->organization_id)
                ->where('performance_act_id', $act->id)
                ->max('version') ?? 0) + 1;
            $sourceHash = hash('sha256', CanonicalJson::encode([
                ...$identity,
                'version' => $version,
            ]));

            return ProductionAcceptanceOwnerVersion::query()->create([
                'organization_id' => (int) $act->contract->organization_id,
                'project_id' => (int) $act->project_id,
                'contract_id' => (int) $act->contract_id,
                'performance_act_id' => (int) $act->id,
                'version' => $version,
                'event_type' => $eventType,
                'effective_at' => $effectiveAt,
                'source_event_id' => $sourceEventId,
                'source_hash' => $sourceHash,
                'members' => $members,
            ]);
        }, 5);
    }

    private function members(ContractPerformanceAct $act): array
    {
        $members = $act->lines->isNotEmpty()
            ? $act->lines->map(function (PerformanceActLine $line): array {
                $work = $line->completedWork;

                return $this->member(
                    'performance_act_line',
                    (int) $line->id,
                    $work,
                    trim((string) $line->unit) !== ''
                        ? (string) $line->unit
                        : (string) ($work?->workType?->measurementUnit?->short_name ?? ''),
                );
            })->all()
            : $act->completedWorks->map(fn ($work): array => $this->member(
                'completed_work',
                (int) $work->id,
                $work,
                (string) ($work->workType?->measurementUnit?->short_name ?? ''),
            ))->all();
        usort($members, static fn (array $left, array $right): int => [
            $left['source_line_type'],
            $left['source_line_id'],
        ] <=> [
            $right['source_line_type'],
            $right['source_line_id'],
        ]);

        return $members;
    }

    private function member(
        string $sourceLineType,
        int $sourceLineId,
        mixed $work,
        string $unitCode,
    ): array {
        if ($work === null || (int) $work->id < 1 || trim($unitCode) === '') {
            throw new InvalidArgumentException('production_acceptance_owner_member_invalid');
        }
        $additionalInfo = is_array($work->additional_info) ? $work->additional_info : [];
        $zone = isset($additionalInfo['zone']) && is_scalar($additionalInfo['zone'])
            ? trim((string) $additionalInfo['zone'])
            : null;

        return [
            'contractor_id' => $work->contractor_id === null ? null : (int) $work->contractor_id,
            'source_line_id' => $sourceLineId,
            'source_line_type' => $sourceLineType,
            'unit_code' => $unitCode,
            'work_id' => (int) $work->id,
            'zone' => $zone,
        ];
    }
}
