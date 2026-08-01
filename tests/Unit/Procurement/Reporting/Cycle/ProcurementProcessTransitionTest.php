<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Cycle;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessDimensionSnapshot;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessTransition;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementProcessEventCode;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementTerminalReason;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProcurementProcessTransitionTest extends TestCase
{
    public function test_transition_has_stable_identity_and_hash_independent_of_dimension_key_order(): void
    {
        $left = $this->transition([
            'schema_version' => 'procurement-process-dimensions.v1',
            'organization_id' => 10,
            'project_id' => 20,
            'purchase_request_id' => 30,
            'purchase_request_line_id' => 40,
            'request_number' => 'PR-42',
            'material_name' => 'Steel',
            'quantity' => '2.500',
            'unit' => 'kg',
            'quality_status' => 'PARTIAL',
            'gap_codes' => ['missing_policy_version'],
        ]);
        $right = $this->transition([
            'unit' => 'kg',
            'quantity' => '2.500',
            'material_name' => 'Steel',
            'request_number' => 'PR-42',
            'purchase_request_line_id' => 40,
            'purchase_request_id' => 30,
            'project_id' => 20,
            'organization_id' => 10,
            'schema_version' => 'procurement-process-dimensions.v1',
            'quality_status' => 'PARTIAL',
            'gap_codes' => ['missing_policy_version'],
        ]);

        self::assertSame($left->idempotencyIdentity(), $right->idempotencyIdentity());
        self::assertSame($left->payloadHash(), $right->payloadHash());
        self::assertSame('procurement-process-events.v1', $left->eventVersion);
        self::assertSame('2026-08-01T09:30:00.123456Z', $left->occurredAtUtc());
    }

    public function test_transition_pins_policy_and_calendar_hashes_together(): void
    {
        $dimensions = $this->baseDimensions();
        $dimensions['quality_status'] = 'FULL';
        $dimensions['gap_codes'] = [];
        $dimensions['policy_version_id'] = 70;
        $dimensions['policy_hash'] = str_repeat('a', 64);
        $dimensions['calendar_version'] = 'procurement-business-calendar.v1';
        $dimensions['calendar_hash'] = str_repeat('b', 64);

        $transition = new ProcurementProcessTransition(
            eventCode: ProcurementProcessEventCode::REQUEST_CREATED,
            organizationId: 10,
            projectId: 20,
            purchaseRequestId: 30,
            purchaseRequestLineId: 40,
            occurredAt: new DateTimeImmutable('2026-08-01T09:30:00+00:00'),
            sourceKind: 'purchase_request',
            sourceId: 30,
            policyVersionId: 70,
            policyHash: str_repeat('a', 64),
            calendarVersion: 'procurement-business-calendar.v1',
            calendarHash: str_repeat('b', 64),
            dimensionSnapshot: ProcurementProcessDimensionSnapshot::fromArray($dimensions),
        );

        self::assertSame(70, $transition->policyVersionId);
        self::assertSame(str_repeat('b', 64), $transition->calendarHash);
    }

    #[DataProvider('unsafeDimensionProvider')]
    public function test_dimension_snapshot_rejects_secret_or_mutable_payload(array $unsafe): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProcurementProcessDimensionSnapshot::fromArray(array_merge($this->baseDimensions(), $unsafe));
    }

    public static function unsafeDimensionProvider(): array
    {
        return [
            'public url' => [['public_url' => 'https://example.test/token']],
            'email value' => [['material_name' => 'buyer@example.test']],
            'nested token' => [['supplier' => ['token' => 'secret']]],
            'arbitrary comment' => [['comment' => 'internal']],
            'raw audit payload' => [['audit_payload' => ['anything' => true]]],
            'supplier snapshot' => [['supplier_snapshot' => ['name' => 'Supplier']]],
        ];
    }

    public function test_transition_rejects_missing_project_lineage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProcurementProcessTransition(
            eventCode: ProcurementProcessEventCode::REQUEST_CREATED,
            organizationId: 10,
            projectId: 0,
            purchaseRequestId: 30,
            purchaseRequestLineId: 40,
            occurredAt: new DateTimeImmutable('2026-08-01T09:30:00+00:00'),
            sourceKind: 'purchase_request',
            sourceId: 30,
            dimensionSnapshot: ProcurementProcessDimensionSnapshot::fromArray($this->baseDimensions()),
        );
    }

    public function test_unassigned_project_is_recorded_only_as_explicit_partial_gap(): void
    {
        $dimensions = $this->baseDimensions();
        $dimensions['project_id'] = null;
        $dimensions['quality_status'] = 'PARTIAL';
        $dimensions['gap_codes'] = ['missing_policy_version', 'missing_project_lineage'];

        $transition = new ProcurementProcessTransition(
            eventCode: ProcurementProcessEventCode::REQUEST_CREATED,
            organizationId: 10,
            projectId: null,
            purchaseRequestId: 30,
            purchaseRequestLineId: 40,
            occurredAt: new DateTimeImmutable('2026-08-01T09:30:00+00:00'),
            sourceKind: 'purchase_request',
            sourceId: 30,
            dimensionSnapshot: ProcurementProcessDimensionSnapshot::fromArray($dimensions),
        );

        self::assertNull($transition->projectId);
        self::assertSame(
            ['missing_policy_version', 'missing_project_lineage'],
            $transition->dimensionSnapshot->values['gap_codes'],
        );
    }

    public function test_quarantine_snapshot_rejects_current_project_reconstruction(): void
    {
        $dimensions = $this->baseDimensions();
        $dimensions['gap_codes'] = [
            'missing_policy_version',
            'missing_project_lineage',
            'missing_request_created_event',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('procurement_process_dimension_quarantine_invalid');

        ProcurementProcessDimensionSnapshot::fromArray($dimensions);
    }

    public function test_quarantine_snapshot_requires_all_evidence_gaps(): void
    {
        $dimensions = $this->baseDimensions();
        $dimensions['project_id'] = null;
        $dimensions['gap_codes'] = [
            'missing_project_lineage',
            'missing_request_created_event',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('procurement_process_dimension_quarantine_invalid');

        ProcurementProcessDimensionSnapshot::fromArray($dimensions);
    }

    public function test_quarantine_snapshot_rejects_policy_or_mutable_dimension_reconstruction(): void
    {
        $dimensions = $this->baseDimensions();
        $dimensions['project_id'] = null;
        $dimensions['request_number'] = 'CURRENT-PR-42';
        $dimensions['gap_codes'] = [
            'missing_policy_version',
            'missing_project_lineage',
            'missing_request_created_event',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('procurement_process_dimension_quarantine_invalid');

        ProcurementProcessDimensionSnapshot::fromArray($dimensions);
    }

    public function test_partial_snapshot_without_gap_codes_is_rejected(): void
    {
        $dimensions = $this->baseDimensions();
        $dimensions['quality_status'] = 'PARTIAL';
        $dimensions['gap_codes'] = [];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('procurement_process_dimension_partial_gaps_required');

        ProcurementProcessDimensionSnapshot::fromArray($dimensions);
    }

    public function test_dimension_snapshot_rejects_non_scalar_business_fields(): void
    {
        $dimensions = $this->baseDimensions();
        $dimensions['material_name'] = ['Steel'];

        $this->expectException(InvalidArgumentException::class);
        ProcurementProcessDimensionSnapshot::fromArray($dimensions);
    }

    public function test_transition_rejects_dimension_lineage_mismatch(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProcurementProcessTransition(
            eventCode: ProcurementProcessEventCode::REQUEST_CREATED,
            organizationId: 10,
            projectId: 20,
            purchaseRequestId: 30,
            purchaseRequestLineId: 41,
            occurredAt: new DateTimeImmutable('2026-08-01T09:30:00+00:00'),
            sourceKind: 'purchase_request',
            sourceId: 30,
            dimensionSnapshot: ProcurementProcessDimensionSnapshot::fromArray($this->baseDimensions()),
        );
    }

    public function test_cancelled_transition_requires_typed_terminal_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProcurementProcessTransition(
            eventCode: ProcurementProcessEventCode::CANCELLED,
            organizationId: 10,
            projectId: 20,
            purchaseRequestId: 30,
            purchaseRequestLineId: 40,
            occurredAt: new DateTimeImmutable('2026-08-01T09:30:00+00:00'),
            sourceKind: 'purchase_request',
            sourceId: 30,
            dimensionSnapshot: ProcurementProcessDimensionSnapshot::fromArray($this->baseDimensions()),
        );
    }

    public function test_cancelled_transition_hashes_typed_terminal_reason(): void
    {
        $transition = new ProcurementProcessTransition(
            eventCode: ProcurementProcessEventCode::CANCELLED,
            organizationId: 10,
            projectId: 20,
            purchaseRequestId: 30,
            purchaseRequestLineId: 40,
            occurredAt: new DateTimeImmutable('2026-08-01T09:30:00+00:00'),
            sourceKind: 'purchase_request',
            sourceId: 30,
            dimensionSnapshot: ProcurementProcessDimensionSnapshot::fromArray($this->baseDimensions()),
            terminalReason: ProcurementTerminalReason::REQUEST_REJECTED,
        );

        self::assertSame('request_rejected', $transition->canonicalPayload()['terminal_reason']);
    }

    public function test_transition_rejects_orphan_optional_lineage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProcurementProcessTransition(
            eventCode: ProcurementProcessEventCode::SUPPLIER_RESPONDED,
            organizationId: 10,
            projectId: 20,
            purchaseRequestId: 30,
            purchaseRequestLineId: 40,
            occurredAt: new DateTimeImmutable('2026-08-01T09:30:00+00:00'),
            sourceKind: 'supplier_proposal_version',
            sourceId: 70,
            dimensionSnapshot: ProcurementProcessDimensionSnapshot::fromArray($this->baseDimensions()),
            supplierProposalVersionId: 70,
        );
    }

    #[DataProvider('invalidRelationalLineageProvider')]
    public function test_transition_rejects_structurally_incomplete_relational_lineage(array $lineage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('procurement_process_transition_optional_lineage_incomplete');

        new ProcurementProcessTransition(...[
            'eventCode' => ProcurementProcessEventCode::SUPPLIER_RESPONDED,
            'organizationId' => 10,
            'projectId' => 20,
            'purchaseRequestId' => 30,
            'purchaseRequestLineId' => 40,
            'occurredAt' => new DateTimeImmutable('2026-08-01T09:30:00+00:00'),
            'sourceKind' => 'supplier_proposal_version',
            'sourceId' => 70,
            'dimensionSnapshot' => ProcurementProcessDimensionSnapshot::fromArray($this->baseDimensions()),
            ...$lineage,
        ]);
    }

    public static function invalidRelationalLineageProvider(): array
    {
        return [
            'supplier request requires party' => [[
                'supplierRequestId' => 60,
            ]],
            'proposal chain requires party' => [[
                'supplierRequestId' => 60,
                'supplierProposalId' => 70,
                'supplierProposalVersionId' => 71,
            ]],
            'order requires party' => [[
                'purchaseOrderId' => 80,
            ]],
            'accepted proposal pair requires version' => [[
                'supplierRequestId' => 60,
                'supplierPartyId' => 65,
                'supplierProposalId' => 70,
                'purchaseOrderId' => 80,
            ]],
            'proposal requires immutable version without order' => [[
                'supplierRequestId' => 60,
                'supplierPartyId' => 65,
                'supplierProposalId' => 70,
            ]],
            'proposal version requires proposal' => [[
                'supplierRequestId' => 60,
                'supplierPartyId' => 65,
                'supplierProposalVersionId' => 71,
            ]],
        ];
    }

    private function transition(array $dimensions): ProcurementProcessTransition
    {
        return new ProcurementProcessTransition(
            eventCode: ProcurementProcessEventCode::REQUEST_CREATED,
            organizationId: 10,
            projectId: 20,
            purchaseRequestId: 30,
            purchaseRequestLineId: 40,
            occurredAt: new DateTimeImmutable('2026-08-01T12:30:00.123456+03:00'),
            sourceKind: 'purchase_request',
            sourceId: 30,
            actorId: 50,
            policyVersionId: null,
            dimensionSnapshot: ProcurementProcessDimensionSnapshot::fromArray($dimensions),
        );
    }

    private function baseDimensions(): array
    {
        return [
            'schema_version' => 'procurement-process-dimensions.v1',
            'organization_id' => 10,
            'project_id' => 20,
            'purchase_request_id' => 30,
            'purchase_request_line_id' => 40,
            'request_number' => 'PR-42',
            'material_name' => 'Steel',
            'quantity' => '2.500',
            'unit' => 'kg',
            'quality_status' => 'PARTIAL',
            'gap_codes' => ['missing_policy_version'],
        ];
    }
}
