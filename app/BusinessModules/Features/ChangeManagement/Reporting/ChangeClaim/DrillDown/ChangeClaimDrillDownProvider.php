<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DrillDown;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResourceLink;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Queries\ChangeClaimRowQuery;
use DomainException;

final readonly class ChangeClaimDrillDownProvider implements ReportDrillDownProvider
{
    public function __construct(private ChangeClaimRowQuery $rows)
    {
    }

    public function drillDown(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownRequest $request,
    ): ReportDrillDownResult {
        $payload = $this->tokenPayload($request->token);
        if (($payload['organization_id'] ?? null) !== $context->scope->organizationId
            || ($payload['snapshot_id'] ?? null) !== $snapshot->id
            || ($payload['source_hash'] ?? null) !== $snapshot->sourceHash->value
            || !is_string($payload['row_key'] ?? null)) {
            throw new DomainException('report_drill_down_token_invalid');
        }
        $record = $this->rows->row($context, $snapshot, $payload['row_key']);
        $scoped = [];
        foreach ($context->scope->resources as $resource) {
            $scoped[$resource->kind.':'.$resource->id] = true;
        }
        $rows = [];
        $links = [];
        foreach ((array) $record->source_refs as $ref) {
            if (!is_array($ref) || !is_string($ref['type'] ?? null)) {
                continue;
            }
            $type = $ref['type'];
            $id = (string) ($ref['id'] ?? '');
            if (!ctype_digit($id) || !isset($scoped[$type.':'.$id])) {
                continue;
            }
            $rows[] = ['row_key' => $type.':'.$id, 'source_type' => $type, 'source_id' => $id];
            $links[] = new ReportResourceLink($type, 'r'.$id, $this->routeName($type), ['id' => (int) $id], 'available');
        }

        return new ReportDrillDownResult($rows, null, $links);
    }

    private function tokenPayload(string $token): array
    {
        $encoded = explode('.', $token, 2)[0];
        $decoded = base64_decode(strtr($encoded, '-_', '+/').str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
        $payload = is_string($decoded) ? json_decode($decoded, true) : null;
        if (!is_array($payload) || array_is_list($payload)) {
            throw new DomainException('report_token_invalid');
        }

        return $payload;
    }

    private function routeName(string $type): string
    {
        return match ($type) {
            'change_request' => 'admin.change-management.changes.show',
            'change_claim' => 'admin.change-management.claims.show',
            'contract_allocation' => 'admin.contracts.show',
            'budget_line' => 'admin.budgeting.lines.show',
            'schedule_task' => 'admin.schedules.tasks.show',
            default => throw new DomainException('report_drill_down_source_invalid'),
        };
    }
}
