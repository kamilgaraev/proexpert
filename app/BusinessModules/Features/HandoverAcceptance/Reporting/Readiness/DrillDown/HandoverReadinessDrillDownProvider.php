<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\DrillDown;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Models\HandoverReadinessRow;
use InvalidArgumentException;
use JsonException;

final readonly class HandoverReadinessDrillDownProvider implements ReportDrillDownProvider
{
    public function drillDown(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownRequest $request,
    ): ReportDrillDownResult {
        if (
            $snapshot->kind !== 'handover_readiness'
            || $context->scope->organizationId !== $snapshot->scope->organizationId
        ) {
            throw new InvalidArgumentException('handover_readiness_drill_down_invalid');
        }
        $rowKey = $this->rowKey($request->token, $snapshot);
        $row = HandoverReadinessRow::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id)
            ->where('row_key', $rowKey)
            ->firstOrFail();
        $rows = array_slice(array_map(
            static fn (array $ref): array => [
                'row_key' => (string) $ref['event_id'],
                'event_id' => (string) $ref['event_id'],
                'source_type' => (string) $ref['source_type'],
                'source_id' => (int) $ref['source_id'],
            ],
            $row->evidence_refs,
        ), 0, $request->limit);

        return new ReportDrillDownResult($rows, null, []);
    }

    private function rowKey(string $token, ReportSnapshotRef $snapshot): string
    {
        try {
            $encoded = explode('.', $token, 2)[0] ?? '';
            $json = base64_decode(strtr($encoded, '-_', '+/').str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
            $payload = is_string($json) ? json_decode($json, true, 32, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $payload = null;
        }
        if (
            !is_array($payload)
            || ($payload['snapshot_id'] ?? null) !== $snapshot->id
            || ($payload['source_hash'] ?? null) !== $snapshot->sourceHash->value
            || !is_string($payload['row_key'] ?? null)
        ) {
            throw new InvalidArgumentException('handover_readiness_drill_down_token_invalid');
        }

        return $payload['row_key'];
    }
}
