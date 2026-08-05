<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingAllocationContextSnapshot;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAllocationFactVersion;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HoldingReportingContextContractTest extends TestCase
{
    #[Test]
    public function allocation_context_accepts_only_resolved_percentage_in_closed_range(): void
    {
        $snapshot = new HoldingAllocationContextSnapshot(
            1,
            2,
            3,
            4,
            5,
            'fixed',
            '25.00000000',
            hash('sha256', 'allocation-context'),
            '2026-08-05T10:00:00+00:00',
        );

        self::assertSame('25.00000000', $snapshot->allocatedPercentage);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('holding_allocation_context_snapshot_invalid');
        new HoldingAllocationContextSnapshot(
            1,
            2,
            3,
            4,
            5,
            'fixed',
            '100.00000001',
            hash('sha256', 'allocation-context'),
            '2026-08-05T10:00:00+00:00',
        );
    }

    #[Test]
    public function migration_establishes_one_checkpoint_and_append_only_future_context(): void
    {
        $source = $this->source(
            'database/migrations/2026_08_05_010000_create_holding_reporting_context_evidence.php',
        );

        foreach ([
            'holding_contract_dimension_events',
            'holding_organization_hierarchy_events',
            'holding_allocation_context_events',
            "'total_amount'",
            "'currency'",
            "'contract_dimensions'",
            "'organization_hierarchy'",
            "'allocation_dimensions'",
        ] as $contract) {
            self::assertStringContainsString($contract, $source);
        }
        self::assertSame(1, substr_count($source, '$checkpointAt = is_object'));
        self::assertStringContainsString(
            'most_prevent_holding_reporting_context_mutation_v1()',
            $source,
        );
        self::assertStringContainsString(
            'most_capture_holding_allocation_context_checkpoint_v1(?::timestamptz)',
            $source,
        );
        self::assertStringNotContainsString('contracts.created_at AS observed_at', $source);
        self::assertStringNotContainsString('allocations.created_at AS observed_at', $source);
    }

    #[Test]
    public function live_fact_producers_resolve_only_immutable_context_at_business_time(): void
    {
        $payment = $this->source(
            'app/BusinessModules/Core/MultiOrganization/Reporting/Listeners/'
            .'ProjectHoldingAllocationFacts.php',
        );
        $accepted = $this->source(
            'app/BusinessModules/Core/MultiOrganization/Reporting/Services/'
            .'AcceptedWorkHoldingFactProducer.php',
        );

        foreach ([$payment, $accepted] as $source) {
            self::assertStringContainsString('$this->hierarchies->resolveAt(', $source);
            self::assertStringContainsString('$this->contractDimensions->resolve(', $source);
            self::assertStringContainsString('$this->allocationContexts->resolve(', $source);
        }
        self::assertStringNotContainsString('calculateAllocatedAmount()', $payment);
        self::assertStringNotContainsString('Contract::query()', $payment);
        self::assertStringNotContainsString('ContractAllocationHistory::query()', $accepted);

        $projector = $this->source(
            'app/BusinessModules/Core/MultiOrganization/Reporting/Services/'
            .'HoldingAllocationFactProjector.php',
        );
        self::assertStringContainsString(
            '$organizationId = (int) $contractVersion->organization_id',
            $projector,
        );
        self::assertStringContainsString('ContractAllocationTypeEnum::AUTO', $projector);
        self::assertStringContainsString('ContractAllocationTypeEnum::CUSTOM', $projector);
    }

    #[Test]
    public function allocation_resolver_selects_latest_state_per_allocation_before_active_choice(): void
    {
        $source = $this->source(
            'app/BusinessModules/Core/MultiOrganization/Reporting/Services/'
            .'HoldingAllocationContextResolver.php',
        );

        self::assertStringContainsString('PARTITION BY allocation_id', $source);
        self::assertStringContainsString("->where('timeline_position', 1)", $source);
        self::assertStringContainsString("->where('is_deleted', false)", $source);
        self::assertStringContainsString("->where('is_active', true)", $source);
        self::assertStringContainsString("->where('allocation_id', \$allocationId)", $source);
    }

    #[Test]
    public function payment_event_pins_report_context_before_queued_processing(): void
    {
        $event = $this->source('app/BusinessModules/Core/Payments/Events/PaymentDocumentPaid.php');
        $stateMachine = $this->source(
            'app/BusinessModules/Core/Payments/Services/PaymentDocumentStateMachine.php',
        );
        $listener = $this->source(
            'app/BusinessModules/Core/MultiOrganization/Reporting/Listeners/'
            .'ProjectHoldingAllocationFacts.php',
        );

        foreach ([
            'public ?int $organizationId',
            'public ?int $projectId',
            'public ?string $invoiceableType',
            'public ?int $invoiceableId',
            'public ?string $currency',
        ] as $field) {
            self::assertStringContainsString($field, $event);
        }
        foreach ([
            'recognizedAt: $document->paid_at',
            'organizationId: (int) $document->organization_id',
            'invoiceableType: $document->invoiceable_type',
            'currency: $document->currency',
        ] as $capture) {
            self::assertStringContainsString($capture, $stateMachine);
        }
        self::assertStringContainsString('$event->organizationId ?? $document->organization_id', $listener);
        self::assertStringContainsString('$event->currency ?? $document->currency', $listener);
        self::assertStringContainsString("'currency_mismatch'", $listener);
    }

    #[Test]
    public function materializer_reads_only_dimension_complete_v2_facts(): void
    {
        $sources = [
            $this->source(
                'app/BusinessModules/Core/MultiOrganization/Reporting/Services/'
                .'HoldingPerformanceSnapshotMaterializer.php',
            ),
            $this->source(
                'app/BusinessModules/Core/MultiOrganization/Reporting/Services/'
                .'IntercompanyContractFlowSnapshotMaterializer.php',
            ),
            $this->source(
                'app/BusinessModules/Core/MultiOrganization/Reporting/Readiness/'
                .'HoldingPerformanceReadinessProbe.php',
            ),
            $this->source(
                'app/BusinessModules/Core/MultiOrganization/Reporting/Readiness/'
                .'IntercompanyContractFlowReadinessProbe.php',
            ),
        ];

        self::assertSame('holding_allocation_facts.v2', HoldingAllocationFactVersion::SOURCE_SCHEMA_VERSION);
        foreach ($sources as $source) {
            self::assertStringContainsString(
                "->where('source_schema_version', HoldingAllocationFactVersion::SOURCE_SCHEMA_VERSION)",
                $source,
            );
        }
        $holdingMaterializer = $sources[0];
        foreach ([
            '$record->contractor_id',
            '$record->contract_status',
            '$record->work_type_category',
            '$record->contract_dimension_hash',
        ] as $dimension) {
            self::assertStringContainsString($dimension, $holdingMaterializer);
        }
        self::assertSame(2, count(array_filter(
            array_slice($sources, 0, 2),
            static fn (string $source): bool => str_contains(
                $source,
                'ReportErrorCode::REPORT_SOURCE_UNAVAILABLE',
            ),
        )));
    }

    private function source(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 4).'/'.$path);
        self::assertIsString($source);

        return $source;
    }
}
