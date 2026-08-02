<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\DrillDown;

use App\BusinessModules\Core\Reporting\Application\Access\ReportSourceObjectAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Rows\StableDrillDownPage;
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
    public function __construct(private ReportSourceObjectAuthorizer $sources) {}

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
        $evidence = [];
        foreach ($row->evidence_refs as $ref) {
            $sourceType = (string) $ref['source_type'];
            $sourceId = (int) $ref['source_id'];
            $availability = $this->sources->availability(
                $context,
                $sourceType,
                $sourceId,
                (int) $row->organization_id,
                (int) $row->project_id,
            );
            $evidence[] = $availability === 'available'
                ? [
                    'row_key' => (string) $ref['event_id'],
                    'event_id' => (string) $ref['event_id'],
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'availability' => $availability,
                ]
                : [
                    'row_key' => 'redacted:'.hash('sha256', (string) $ref['event_id']),
                    'source_type' => $sourceType,
                    'availability' => 'redacted',
                ];
        }
        $page = StableDrillDownPage::fromRows(
            $evidence,
            $request->cursor,
            $request->limit,
        );

        return new ReportDrillDownResult($page->rows, $page->nextCursor, []);
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
            ! is_array($payload)
            || ($payload['snapshot_id'] ?? null) !== $snapshot->id
            || ($payload['source_hash'] ?? null) !== $snapshot->sourceHash->value
            || ! is_string($payload['row_key'] ?? null)
        ) {
            throw new InvalidArgumentException('handover_readiness_drill_down_token_invalid');
        }

        return $payload['row_key'];
    }
}
