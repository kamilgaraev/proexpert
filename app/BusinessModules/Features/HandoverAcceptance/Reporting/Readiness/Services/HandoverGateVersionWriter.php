<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\DTO\HandoverGateDefinition;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Models\HandoverGateVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class HandoverGateVersionWriter
{
    private const REQUIRED_HARD_BLOCKERS = ['rfi', 'change', 'quality_defect', 'constraint'];

    public function append(
        AcceptanceScope $scope,
        string $gateCode,
        array $requiredChecklistCodes,
        array $requiredDocumentCodes,
        array $hardBlockerSourceTypes,
        bool $explicitlyEmptyRequirements,
        array $duePolicy,
        CarbonImmutable $effectiveFrom,
        ?CarbonImmutable $effectiveTo = null,
    ): HandoverGateVersion {
        new HandoverGateDefinition(
            $gateCode,
            $requiredChecklistCodes,
            $requiredDocumentCodes,
            $hardBlockerSourceTypes,
            $explicitlyEmptyRequirements,
        );
        if (
            ! $scope->exists
            || ($effectiveTo !== null && $effectiveTo <= $effectiveFrom)
            || ! $this->validDuePolicy($duePolicy)
            || array_diff(self::REQUIRED_HARD_BLOCKERS, $hardBlockerSourceTypes) !== []
        ) {
            throw new InvalidArgumentException('handover_gate_version_invalid');
        }

        return DB::transaction(function () use (
            $scope,
            $gateCode,
            $requiredChecklistCodes,
            $requiredDocumentCodes,
            $hardBlockerSourceTypes,
            $explicitlyEmptyRequirements,
            $duePolicy,
            $effectiveFrom,
            $effectiveTo,
        ): HandoverGateVersion {
            $lockedScope = AcceptanceScope::query()
                ->whereKey((int) $scope->id)
                ->where('organization_id', (int) $scope->organization_id)
                ->where('project_id', (int) $scope->project_id)
                ->lockForUpdate()
                ->firstOrFail();
            $packageId = $lockedScope->handoverPackage()->value('id');
            $last = HandoverGateVersion::query()
                ->where('organization_id', (int) $lockedScope->organization_id)
                ->where('acceptance_scope_id', (int) $lockedScope->id)
                ->lockForUpdate()
                ->orderByDesc('gate_version')
                ->first();
            $gateVersion = $last === null ? 1 : ((int) $last->gate_version) + 1;
            $payload = [
                'acceptance_scope_id' => (int) $lockedScope->id,
                'due_policy' => $duePolicy,
                'effective_from' => $effectiveFrom->toISOString(),
                'effective_to' => $effectiveTo?->toISOString(),
                'explicitly_empty_requirements' => $explicitlyEmptyRequirements,
                'gate_code' => $gateCode,
                'gate_version' => $gateVersion,
                'hard_blocker_source_types' => $hardBlockerSourceTypes,
                'location_id' => $lockedScope->project_location_id === null
                    ? null
                    : (int) $lockedScope->project_location_id,
                'organization_id' => (int) $lockedScope->organization_id,
                'package_id' => $packageId === null ? null : (int) $packageId,
                'project_id' => (int) $lockedScope->project_id,
                'required_checklist_codes' => $requiredChecklistCodes,
                'required_document_codes' => $requiredDocumentCodes,
            ];

            return HandoverGateVersion::query()->create([
                ...$payload,
                'source_hash' => hash('sha256', CanonicalJson::encode($payload)),
            ]);
        }, 3);
    }

    private function validDuePolicy(array $duePolicy): bool
    {
        if (array_keys($duePolicy) !== ['due_on']) {
            return false;
        }

        return $duePolicy['due_on'] === null
            || (is_string($duePolicy['due_on'])
                && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $duePolicy['due_on']) === 1);
    }
}
