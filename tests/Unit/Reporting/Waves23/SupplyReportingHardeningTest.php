<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use PHPUnit\Framework\TestCase;

final class SupplyReportingHardeningTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 4);
    }

    public function test_owner_export_uses_nullable_safe_keyset_without_offset(): void
    {
        $source = $this->source('app/Support/Reporting/EloquentOwnerReportRows.php');

        self::assertStringNotContainsString('->offset(', $source);
        self::assertStringContainsString('applyPosition(', $source);
        self::assertStringContainsString('orWhereNull($sort->field)', $source);
        self::assertStringContainsString('CASE WHEN {$sort->field} IS NULL', $source);
    }

    public function test_real_mutation_services_depend_on_reporting_recorders(): void
    {
        foreach ([
            'app/BusinessModules/Features/Procurement/Services/PurchaseRequestService.php',
            'app/BusinessModules/Features/Procurement/Services/SupplierRequestService.php',
            'app/BusinessModules/Features/Procurement/Services/SupplierProposalService.php',
            'app/BusinessModules/Features/Procurement/Services/SupplierProposalComparisonService.php',
            'app/BusinessModules/Features/Procurement/Services/PurchaseOrderService.php',
        ] as $file) {
            self::assertStringContainsString('reportingLifecycle', $this->source($file), $file);
        }
        self::assertStringContainsString(
            'WarehouseInventoryEventRecorder',
            $this->source('app/BusinessModules/Features/BasicWarehouse/Services/WarehouseService.php'),
        );
    }

    public function test_backfills_are_bounded_resumable_and_queue_only(): void
    {
        foreach ([
            'Procurement/Reporting/Cycle/Backfill/RunProcurementCycleBackfillSliceJob.php',
            'Procurement/Reporting/Award/Backfill/RunSupplierAwardBackfillSliceJob.php',
            'Procurement/Reporting/Supply/Backfill/RunSupplyReliabilityBackfillSliceJob.php',
            'BasicWarehouse/Reporting/InventoryRisk/Backfill/RunInventoryRiskBackfillSliceJob.php',
        ] as $suffix) {
            $source = $this->source('app/BusinessModules/Features/'.$suffix);
            self::assertStringContainsString('ShouldBeUnique', $source);
            self::assertStringContainsString('reports-source-backfill', $source);
            self::assertStringContainsString('500', $source);
            self::assertStringContainsString('nextCursor', $source);
        }
    }

    public function test_inventory_backfill_uses_canonical_movement_date_and_id_cursor(): void
    {
        $source = $this->source(
            'app/BusinessModules/Features/BasicWarehouse/Reporting/InventoryRisk/Backfill/'
            .'InventoryRiskBackfill.php',
        );

        self::assertStringContainsString("->orderBy('movement_date')", $source);
        self::assertStringContainsString("'movement_date' =>", $source);
        self::assertStringContainsString("->where('id', '>', \$position['id'])", $source);
    }

    public function test_single_receipt_can_close_both_first_and_full_cycle_events(): void
    {
        $source = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/ProcurementReportingLifecycleRecorder.php',
        );

        self::assertStringContainsString("\$eventCodes[] = 'first_receipt'", $source);
        self::assertStringContainsString("\$eventCodes[] = 'fully_received'", $source);
        self::assertStringContainsString('foreach ($eventCodes as $eventCode)', $source);
    }

    public function test_supply_backfill_requires_explicit_unit_and_posted_timestamp_evidence(): void
    {
        $source = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/Supply/Backfill/'
            .'SupplyReliabilityBackfill.php',
        );

        self::assertStringNotContainsString("'unit-code:'.hash", $source);
        self::assertStringNotContainsString('->endOfDay()', $source);
        self::assertStringContainsString("'reporting_unit_dimension'", $source);
        self::assertStringContainsString("'reporting_conversion_version'", $source);
        self::assertStringContainsString("'reporting_posted_at'", $source);
        self::assertStringContainsString("'reporting_return_events'", $source);
    }

    public function test_receipt_reversal_api_is_authorized_and_controller_stays_thin(): void
    {
        $routes = $this->source('app/BusinessModules/Features/Procurement/routes.php');
        $controller = $this->source(
            'app/BusinessModules/Features/Procurement/Http/Controllers/PurchaseOrderController.php',
        );
        $method = substr($controller, strpos($controller, 'public function reverseReceiptLine('));
        $method = substr($method, 0, strpos($method, 'public function receiptDocumentPdf('));

        self::assertStringContainsString(
            "Route::post('/{id}/receipt-lines/{line}/reverse'",
            $routes,
        );
        self::assertStringContainsString(
            "middleware('authorize:procurement.purchase_orders.receive')",
            $routes,
        );
        self::assertStringContainsString('$this->service->reverseReceiptLine(', $method);
        self::assertStringNotContainsString('::query()', $method);
        self::assertStringContainsString('AdminResponse::success(', $method);
    }

    public function test_supply_exports_delegate_to_project_scoped_keyset_cursor(): void
    {
        foreach ([
            'Procurement/Reporting/Supply/Queries/SupplyReliabilityRowQuery.php',
            'BasicWarehouse/Reporting/InventoryRisk/Queries/InventoryRiskRowQuery.php',
        ] as $suffix) {
            $query = $this->source('app/BusinessModules/Features/'.$suffix);
            $cursor = substr($query, strpos($query, 'public function cursor('));

            self::assertStringContainsString('$this->rows->cursor(', $cursor, $suffix);
            self::assertStringContainsString("'project_id'", $cursor, $suffix);
            self::assertStringNotContainsString('offset(', $cursor, $suffix);
        }
    }

    public function test_readiness_is_bound_to_requested_project_scope(): void
    {
        foreach ([
            'Procurement/Reporting/Cycle/Readiness/ProcurementCycleReadinessProbe.php',
            'Procurement/Reporting/Award/Readiness/SupplierAwardReadinessProbe.php',
            'Procurement/Reporting/Supply/Readiness/SupplyReliabilityReadinessProbe.php',
            'BasicWarehouse/Reporting/InventoryRisk/Readiness/InventoryRiskReadinessProbe.php',
        ] as $suffix) {
            $source = $this->source('app/BusinessModules/Features/'.$suffix);
            if (str_contains($suffix, 'SupplierAwardReadinessProbe')) {
                self::assertStringContainsString('$this->universe->query($context, $query)', $source, $suffix);
                $source = $this->source(
                    'app/BusinessModules/Features/Procurement/Reporting/Award/Queries/'
                    .'SupplierAwardFilteredUniverse.php',
                );
            }
            self::assertStringContainsString('$context->scope->projectIds', $source, $suffix);
            self::assertStringContainsString('whereIn', $source, $suffix);
        }
    }

    public function test_rows_and_materializers_apply_the_exact_resource_scope(): void
    {
        foreach ([
            [
                'Procurement/Reporting/Cycle/Queries/ProcurementCycleRowQuery.php',
                'Procurement/Reporting/Cycle/Services/ProcurementCycleSnapshotMaterializer.php',
                "'purchase_request_line'",
                "'purchase_request_line_id'",
            ],
            [
                'Procurement/Reporting/Award/Queries/SupplierAwardRowQuery.php',
                'Procurement/Reporting/Award/Services/SupplierAwardSnapshotMaterializer.php',
                "'supplier_award_decision'",
                "'decision_id'",
            ],
            [
                'Procurement/Reporting/Supply/Queries/SupplyReliabilityRowQuery.php',
                'Procurement/Reporting/Supply/Services/SupplyReliabilitySnapshotMaterializer.php',
                "'purchase_order_item'",
                "'purchase_order_item_id'",
            ],
            [
                'BasicWarehouse/Reporting/InventoryRisk/Queries/InventoryRiskRowQuery.php',
                'BasicWarehouse/Reporting/InventoryRisk/Services/InventoryRiskSnapshotMaterializer.php',
                "'warehouse'",
                "'warehouse_id'",
            ],
        ] as [$queryPath, $materializerPath, $resourceKind, $resourceColumn]) {
            $query = $this->source('app/BusinessModules/Features/'.$queryPath);
            $materializer = $this->source('app/BusinessModules/Features/'.$materializerPath);

            self::assertStringContainsString($resourceKind, $query, $queryPath);
            self::assertStringContainsString($resourceColumn, $query, $queryPath);
            if (str_contains($materializer, '$this->universe->query(')) {
                $universePath = str_contains($materializerPath, '/Award/')
                    ? 'Procurement/Reporting/Award/Queries/SupplierAwardFilteredUniverse.php'
                    : 'Procurement/Reporting/Cycle/Queries/ProcurementCycleFilteredUniverse.php';
                $materializer = $this->source('app/BusinessModules/Features/'.$universePath);
            }
            self::assertStringContainsString('allowedIds(', $materializer, $materializerPath);
            self::assertStringContainsString($resourceKind, $materializer, $materializerPath);
            self::assertStringContainsString('whereIn(', $materializer, $materializerPath);
        }
    }

    public function test_supply_readiness_uses_owner_items_and_counts_missing_projection(): void
    {
        $source = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/Supply/Readiness/'
            .'SupplyReliabilityReadinessProbe.php',
        );

        self::assertStringContainsString('$eligibleItemIds', $source);
        self::assertStringContainsString('$eligiblePromiseIds', $source);
        self::assertStringContainsString(
            "->whereIn('promise_version_id', \$eligiblePromiseIds)",
            $source,
        );
        self::assertStringContainsString('->whereNotExists(', $source);
        self::assertStringContainsString("'readiness_event.promise_version_id'", $source);
        self::assertStringContainsString("'owner_promise.id'", $source);
        self::assertStringContainsString('$missingSent', $source);
        self::assertStringNotContainsString('->get([', $source);
        self::assertStringNotContainsString('->pluck(', $source);
        self::assertStringContainsString('SentPurchaseOrderLineOwner::query()', $source);
        self::assertStringContainsString("'sent_purchase_order_line_owners.effective_from'", $source);
        self::assertStringContainsString('$missingOwner', $source);
        self::assertStringContainsString('$incompleteBackfill', $source);
        self::assertStringContainsString('SupplyReliabilityBackfillWatermark::query()', $source);
        self::assertStringContainsString('$missingPromise', $source);
        self::assertStringContainsString('$eligible - $owned', $source);
        self::assertStringContainsString('$owned - $projected', $source);
        self::assertStringNotContainsString('min($projected, $sentItems)', $source);
    }

    public function test_sent_owner_first_writer_is_serialized_and_compares_the_complete_identity(): void
    {
        $source = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/Supply/Services/'
            .'SentPurchaseOrderLineOwnerRecorder.php',
        );

        self::assertStringContainsString('pg_advisory_xact_lock', $source);
        self::assertStringContainsString('$existing->only(array_keys($attributes))', $source);
        self::assertStringContainsString('$persisted !== $expected', $source);
        self::assertStringContainsString('hash_equals(', $source);
        self::assertStringNotContainsString('->lockForUpdate()', $source);
    }

    public function test_supply_backfill_has_an_org_scoped_fixed_target_watermark(): void
    {
        $backfill = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/Supply/Backfill/'
            .'SupplyReliabilityBackfill.php',
        );
        $migration = $this->source(
            'app/BusinessModules/Features/Procurement/migrations/'
            .'2026_07_26_120000_create_supply_reliability_reporting_tables.php',
        );

        self::assertStringContainsString('supply_reliability_backfill_watermarks', $migration);
        self::assertStringContainsString("'organization_id'", $migration);
        self::assertStringContainsString("'target_item_id'", $migration);
        self::assertStringContainsString("'completed_item_id'", $migration);
        self::assertStringContainsString("'purchase_order_items.id', '<=', \$watermark->target_item_id", $backfill);
        self::assertStringContainsString("'completed_item_id' => max(", $backfill);
        self::assertStringContainsString('pg_advisory_xact_lock', $backfill);
    }

    public function test_supply_readiness_and_backfill_share_the_frozen_historical_cohort(): void
    {
        $backfill = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/Supply/Backfill/'
            .'SupplyReliabilityBackfill.php',
        );
        $evidence = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/Supply/Backfill/'
            .'SupplyBackfillEvidenceHasher.php',
        );
        $readiness = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/Supply/Readiness/'
            .'SupplyReliabilityReadinessProbe.php',
        );

        self::assertStringContainsString(
            "'purchase_orders.sent_at', '<=', \$historicalCutoff",
            $backfill,
        );
        self::assertStringContainsString(
            "'purchase_orders.sent_at', '<=', \$historicalCutoff",
            $evidence,
        );
        self::assertStringContainsString("'occurred_at', '<=', \$historicalCutoff", $evidence);
        self::assertStringContainsString("'effective_from', '<=', \$historicalCutoff", $evidence);
        self::assertStringContainsString('$watermark->target_sent_at', $readiness);
    }

    public function test_supply_report_uses_strict_period_without_client_owned_scope_filters(): void
    {
        $readiness = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/Supply/Readiness/'
            .'SupplyReliabilityReadinessProbe.php',
        );
        $period = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/Supply/Services/'
            .'SupplyReliabilityPeriod.php',
        );

        self::assertStringContainsString('$this->period->resolve($query)', $readiness);
        self::assertStringContainsString("['period_start']", $period);
        self::assertStringContainsString("['period_end']", $period);
        self::assertStringNotContainsString('OwnerReportFilterApplier', $readiness);
    }

    public function test_owner_materializers_filter_sources_before_hashing_and_serialize_first_writer(): void
    {
        foreach ([
            'Procurement/Reporting/Cycle/Services/ProcurementCycleSnapshotMaterializer.php',
            'Procurement/Reporting/Award/Services/SupplierAwardSnapshotMaterializer.php',
            'Procurement/Reporting/Supply/Services/SupplyReliabilitySnapshotMaterializer.php',
            'BasicWarehouse/Reporting/InventoryRisk/Services/InventoryRiskSnapshotMaterializer.php',
        ] as $suffix) {
            $source = $this->source('app/BusinessModules/Features/'.$suffix);
            self::assertStringContainsString('OwnerSnapshotFirstWriter::run(', $source, $suffix);
            if (str_contains($suffix, 'ProcurementCycleSnapshotMaterializer')
                || str_contains($suffix, 'SupplierAwardSnapshotMaterializer')) {
                self::assertStringContainsString('$this->universe->query(', $source, $suffix);
            } elseif (str_contains($suffix, 'SupplyReliabilitySnapshotMaterializer')) {
                self::assertStringContainsString('$this->period->resolve($query)', $source, $suffix);
                self::assertLessThan(
                    strpos($source, '$sourceHash ='),
                    strpos($source, "whereBetween('owner_promise.promised_at'"),
                    $suffix,
                );
            } else {
                self::assertStringContainsString('OwnerReportFilterApplier', $source, $suffix);
                self::assertLessThan(
                    strpos($source, '$sourceHash ='),
                    strpos($source, '$this->filters->apply('),
                    $suffix,
                );
            }
        }
        $firstWriter = $this->source('app/Support/Reporting/OwnerSnapshotFirstWriter.php');
        self::assertStringContainsString('pg_advisory_xact_lock', $firstWriter);
        self::assertStringContainsString('$query->queryHash->value', $firstWriter);
    }

    public function test_supply_backfill_uses_exact_proven_owner_lifecycle_evidence(): void
    {
        $source = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/Supply/Backfill/'
            .'SupplyReliabilityBackfill.php',
        );

        foreach ([
            "'reporting_sent_at'",
            "'reporting_confirmed_at'",
            "'reporting_cancelled_at'",
            "'owner_timestamp_evidence_hash'",
            "'remediated_owner_timestamp' => true",
        ] as $evidence) {
            self::assertStringContainsString($evidence, $source);
        }
        self::assertStringNotContainsString('startOfDay()', $source);
        self::assertStringNotContainsString('endOfDay()', $source);
    }

    public function test_supply_database_contract_preserves_exact_owner_timestamps(): void
    {
        $source = $this->source(
            'app/BusinessModules/Features/Procurement/migrations/'
            .'2026_07_26_120000_create_supply_reliability_reporting_tables.php',
        );

        self::assertStringContainsString('ALTER COLUMN sent_at TYPE timestamptz', $source);
        self::assertStringContainsString('ALTER COLUMN confirmed_at TYPE timestamptz', $source);
        self::assertStringContainsString('NEW.occurred_at <> source_order.sent_at', $source);
        self::assertStringContainsString('NEW.occurred_at <> source_order.confirmed_at', $source);
        self::assertStringNotContainsString('NEW.occurred_at::date', $source);
    }

    public function test_purchase_order_controller_never_exposes_domain_exception_messages(): void
    {
        $source = $this->source(
            'app/BusinessModules/Features/Procurement/Http/Controllers/PurchaseOrderController.php',
        );

        self::assertStringNotContainsString('DomainException $', $source);
        self::assertStringContainsString(
            "trans_message('procurement.purchase_orders.operation_rejected')",
            $source,
        );
    }

    public function test_production_assembler_exposes_complete_runtime_bindings_for_supply_wave(): void
    {
        $assembler = $this->source(
            'app/BusinessModules/Core/Reporting/Infrastructure/Definitions/'
            .'ProductionReportDefinitionBindingAssembler.php',
        );
        $provider = $this->source(
            'app/BusinessModules/Core/Reporting/ReportingContractsServiceProvider.php',
        );

        foreach ([
            "'procurement_cycle'",
            "'supplier_award_competitiveness'",
            "'supply_reliability'",
            "'inventory_risk'",
        ] as $code) {
            self::assertStringContainsString($code, $assembler);
            self::assertStringContainsString('$registry->published($code)->payload()', $assembler);
        }
        foreach (['provider', 'rows', 'drill_down', 'readiness'] as $port) {
            self::assertStringContainsString("'{$port}'", $assembler);
        }
        self::assertStringContainsString('ReportDefinitionBindingAssembler::class', $provider);
        self::assertStringContainsString('ProductionReportDefinitionBindingAssembler::class', $provider);
    }

    public function test_cycle_filters_select_owner_cohort_before_loading_complete_timeline(): void
    {
        $universe = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/Cycle/Queries/'
            .'ProcurementCycleFilteredUniverse.php',
        );
        $materializer = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/Cycle/Services/'
            .'ProcurementCycleSnapshotMaterializer.php',
        );
        $readiness = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/Cycle/Readiness/'
            .'ProcurementCycleReadinessProbe.php',
        );

        foreach ([
            "cycle_owner.dimensions->>'project_id'",
            "cycle_owner.dimensions->>'requester_id'",
            "cycle_owner.dimensions->>'buyer_id'",
            "cycle_owner.dimensions->>'material_id'",
            "cycle_owner.dimensions->>'category'",
            "cycle_owner.dimensions->>'amount'",
            "cycle_owner.dimensions->>'priority'",
        ] as $persistedDimension) {
            self::assertStringContainsString($persistedDimension, $universe);
        }
        self::assertStringContainsString('$this->universe->query($context, $query)', $materializer);
        self::assertStringContainsString('$this->universe->query($context, $query)', $readiness);
        self::assertStringContainsString(
            "->whereIn('procurement_process_events.purchase_request_line_id', \$eligibleLineIds)",
            $materializer,
        );
    }

    public function test_award_filters_use_server_scope_and_immutable_selection_period(): void
    {
        $source = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/Award/Queries/'
            .'SupplierAwardFilteredUniverse.php',
        );

        self::assertStringContainsString('supplier_award_decision_versions.project_id', $source);
        self::assertStringContainsString("['period_start']", $source);
        self::assertStringContainsString("['period_end']", $source);
        self::assertStringContainsString("whereBetween('supplier_award_decision_versions.selected_at'", $source);
        self::assertStringNotContainsString('OwnerReportFilterApplier', $source);
        self::assertStringNotContainsString('supplier_proposals as', $source);
        self::assertStringNotContainsString('supplier_proposal_lines as', $source);
        self::assertStringNotContainsString('materials as', $source);
        self::assertStringNotContainsString('award_filter_request.project_id', $source);
        self::assertStringNotContainsString("commercial_snapshot->>'material_id'", $source);
        self::assertStringNotContainsString("commercial_snapshot->>'category_id'", $source);
        self::assertStringNotContainsString("commercial_snapshot->>'procurement_method'", $source);
    }

    public function test_supply_filters_and_owner_gaps_are_bounded_by_immutable_as_of_facts(): void
    {
        $materializer = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/Supply/Services/'
            .'SupplyReliabilitySnapshotMaterializer.php',
        );
        $readiness = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/Supply/Readiness/'
            .'SupplyReliabilityReadinessProbe.php',
        );

        foreach ([
            'sent_purchase_order_line_owners.warehouse_id',
            'sent_purchase_order_line_owners.buyer_id',
            'sent_purchase_order_line_owners.priority',
            'owner_promise.promised_at',
            'statusAt(',
            'matchesDelayFilter(',
        ] as $immutableFilter) {
            self::assertStringContainsString($immutableFilter, $materializer);
        }
        self::assertStringContainsString(
            "'authoritative_order.sent_at', '<=', \$query->asOf",
            $readiness,
        );
        self::assertStringContainsString(
            "'occurred_at', '<=', \$query->asOf",
            $readiness,
        );
        self::assertStringNotContainsString("owner_order.metadata->>'buyer_id'", $materializer);
        self::assertStringNotContainsString("owner_request.metadata->>'priority'", $materializer);
    }

    public function test_inventory_readiness_replays_the_same_full_event_history_as_balance_materializer(): void
    {
        $readiness = $this->source(
            'app/BusinessModules/Features/BasicWarehouse/Reporting/InventoryRisk/Readiness/'
            .'InventoryRiskReadinessProbe.php',
        );

        self::assertStringContainsString('$eligible = $grains->count()', $readiness);
        self::assertStringContainsString('$this->grainUniverse->collect(', $readiness);
        self::assertStringContainsString('(clone $events)', $readiness);
        self::assertStringContainsString("'transfer_pair_key'", $readiness);
        self::assertStringNotContainsString('WarehouseMovement::query()', $readiness);
        self::assertStringNotContainsString("'period' => 'warehouse_inventory_events.occurred_at'", $readiness);
    }

    public function test_database_fences_bind_inventory_events_and_receipt_lots_to_sources(): void
    {
        $inventory = $this->source(
            'app/BusinessModules/Features/BasicWarehouse/migrations/'
            .'2026_07_26_130000_create_inventory_risk_reporting_tables.php',
        );
        $supply = $this->source(
            'app/BusinessModules/Features/Procurement/migrations/'
            .'2026_07_26_120000_create_supply_reliability_reporting_tables.php',
        );

        self::assertStringContainsString(
            'warehouse_inventory_event_source_identity',
            $inventory,
        );
        foreach ([
            'source.organization_id <> NEW.organization_id',
            'source.warehouse_id <> NEW.warehouse_id',
            'source.material_id <> NEW.material_id',
            "source.metadata->>'reporting_source_version'",
            'expected_on_hand IS DISTINCT FROM NEW.on_hand_delta',
            'expected_unit_price_minor IS DISTINCT FROM NEW.unit_price_minor',
            'expected_currency IS DISTINCT FROM NEW.currency',
            'NEW.metadata IS DISTINCT FROM OLD.metadata',
            'NEW.price IS DISTINCT FROM OLD.price',
        ] as $identityFence) {
            self::assertStringContainsString($identityFence, $inventory, $identityFence);
        }
        foreach ([
            'purchase_receipt_inventory_lot_source_identity',
            "source_movement.metadata->>'purchase_order_item_id'",
            "source_movement.metadata->>'batch_number'",
            'source_balance.batch_number IS DISTINCT FROM',
            'NEW.original_quantity <> source_line.quantity_received',
            'source_item.purchase_order_id <> source_receipt.purchase_order_id',
            "NEW.unit_dimension IS DISTINCT FROM (source_movement.metadata->>'unit_dimension')",
            'warehouse_reporting_balance_identity',
            'purchase_receipt_reporting_identity',
            'purchase_order_item_reporting_identity',
            'purchase_order_reporting_identity',
            'supply_promise_source_identity',
            'supply_lifecycle_event_source_identity',
            'source_promise.purchase_order_item_id <> NEW.purchase_order_item_id',
            'reversed_event.promise_version_id <> NEW.promise_version_id',
        ] as $identityFence) {
            self::assertStringContainsString($identityFence, $supply, $identityFence);
        }
    }

    public function test_drill_down_is_bounded_by_snapshot_cutoff_and_pinned_source_ids(): void
    {
        $resolver = $this->source('app/Support/Reporting/EloquentOwnerDrillDown.php');

        self::assertStringContainsString('?string $rowSourceIdsColumn = null', $resolver);
        self::assertStringContainsString('?string $sourceCutoffColumn = null', $resolver);
        self::assertStringContainsString("\$query->whereIn('id', \$sourceIds)", $resolver);
        self::assertStringContainsString("\$snapshot->dimensions['as_of']", $resolver);
        self::assertStringContainsString("\$query->where(\$sourceCutoffColumn, '<=', \$cutoff)", $resolver);

        foreach ([
            'Procurement/Reporting/Cycle/Queries/ProcurementCycleRowQuery.php' => [
                "rowSourceIdsColumn: 'process_event_ids'",
                "sourceCutoffColumn: 'occurred_at'",
            ],
            'Procurement/Reporting/Supply/DrillDown/SupplyReliabilityDrillDownProvider.php' => [
                "rowSourceIdsColumn: 'lifecycle_event_ids'",
                "sourceCutoffColumn: 'occurred_at'",
            ],
            'BasicWarehouse/Reporting/InventoryRisk/DrillDown/InventoryRiskDrillDownProvider.php' => [
                "rowSourceIdsColumn: 'inventory_event_ids'",
                "sourceCutoffColumn: 'occurred_at'",
                "'unit_dimension'",
                "'unit_code'",
                "'conversion_version'",
                "rowDayColumn: 'balance_date'",
                "sourceOccurredAtColumn: 'occurred_at'",
            ],
            'Procurement/Reporting/Award/Queries/SupplierAwardRowQuery.php' => [
                "['decision_version']",
                "sourceCutoffColumn: 'selected_at'",
            ],
        ] as $path => $contracts) {
            $source = $this->source('app/BusinessModules/Features/'.$path);
            foreach ($contracts as $contract) {
                self::assertStringContainsString($contract, $source, $path);
            }
        }
    }

    public function test_inventory_planning_grains_share_report_filter_universe_with_events(): void
    {
        $universe = $this->source(
            'app/BusinessModules/Features/BasicWarehouse/Reporting/InventoryRisk/Services/'
            .'InventoryRiskGrainUniverse.php',
        );
        $balances = $this->source(
            'app/BusinessModules/Features/BasicWarehouse/Reporting/InventoryRisk/Services/'
            .'WarehouseDailyBalanceMaterializer.php',
        );
        $readiness = $this->source(
            'app/BusinessModules/Features/BasicWarehouse/Reporting/InventoryRisk/Readiness/'
            .'InventoryRiskReadinessProbe.php',
        );

        foreach ([
            "'warehouse', 'warehouse_id'",
            "'project', 'project_id'",
            "'material', 'material_id'",
            "'category', 'abc', 'xyz'",
        ] as $filterGroup) {
            self::assertStringContainsString($filterGroup, $universe);
            self::assertStringContainsString($filterGroup, $balances);
            self::assertStringContainsString($filterGroup, $readiness);
        }
        self::assertStringContainsString('$query->filters,', $balances);
        self::assertStringContainsString('$query->filters,', $readiness);
        self::assertStringContainsString(
            'inventory_demand_filter_material',
            $universe,
        );
        self::assertStringContainsString(
            'inventory_policy_filter_material',
            $universe,
        );
        self::assertStringContainsString(
            "->where('warehouse_inventory_events.organization_id', \$organizationId)",
            $universe,
        );
        self::assertStringContainsString(
            "->where('inventory_demand_snapshots.approved_at', '<=', \$asOf)",
            $universe,
        );
    }

    public function test_inventory_planning_only_drill_down_uses_pinned_effective_evidence(): void
    {
        $provider = $this->source(
            'app/BusinessModules/Features/BasicWarehouse/Reporting/InventoryRisk/DrillDown/'
            .'InventoryRiskDrillDownProvider.php',
        );

        foreach ([
            "'demand_snapshot_id'",
            "'reorder_policy_version_id'",
            '->whereKey((int) $demandId)',
            '->whereKey((int) $policyId)',
            "->where('organization_id', \$context->scope->organizationId)",
            "'warehouse'",
            "->where('unit_dimension', \$row->getAttribute('unit_dimension'))",
            "->where('unit_code', \$row->getAttribute('unit_code'))",
            "->where('conversion_version', \$row->getAttribute('conversion_version'))",
            "->where('effective_from', '<=', \$asOf)",
            "->orWhere('effective_to', '>', \$asOf)",
            "->where('approved_at', '<=', \$asOf)",
            "\$balanceDate = \$row->getAttribute('balance_date')",
            "'as_of' => \$asOf",
            "'evidence_kind' => \$kind",
        ] as $contract) {
            self::assertStringContainsString($contract, $provider, $contract);
        }
    }

    public function test_award_recorder_validates_every_comparable_version_owner(): void
    {
        $source = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/Award/Services/'
            .'SupplierAwardDecisionVersionRecorder.php',
        );

        self::assertStringContainsString("->whereIn('id', \$comparableProposalVersionIds)", $source);
        self::assertStringContainsString(
            '$versions->count() !== count($comparableProposalVersionIds)',
            $source,
        );
        self::assertStringContainsString(
            'foreach ($comparableProposalVersionIds as $proposalVersionId)',
            $source,
        );
        self::assertStringContainsString(
            '(int) $version->organization_id !== $organizationId',
            $source,
        );
        self::assertStringContainsString(
            '(int) $version->supplier_request_id !== $supplierRequestId',
            $source,
        );
        self::assertStringContainsString(
            "hash('sha256', CanonicalJson::encode(\$version->dimension_snapshot))",
            $source,
        );
        self::assertStringContainsString(
            "(int) (\$version->dimension_snapshot['supplier_party_id'] ?? 0)",
            $source,
        );
    }

    public function test_inventory_opening_reconciliation_is_project_bound(): void
    {
        $source = $this->source(
            'app/BusinessModules/Features/BasicWarehouse/Reporting/InventoryRisk/Backfill/'
            .'InventoryRiskBackfill.php',
        );

        self::assertStringContainsString('OrganizationWarehouse::query()', $source);
        self::assertStringContainsString('$warehouseProjectId !== $projectId', $source);
        self::assertStringNotContainsString(
            "WarehouseBalance::query()\n            ->where('project_id'",
            $source,
        );
    }

    public function test_proposal_versions_have_application_and_database_immutability_fences(): void
    {
        $model = $this->source(
            'app/BusinessModules/Features/Procurement/Models/SupplierProposalVersion.php',
        );
        $migration = $this->source(
            'app/BusinessModules/Features/Procurement/migrations/'
            .'2026_07_26_050100_create_supplier_award_reporting_tables.php',
        );
        $legacyMigration = $this->source(
            'app/BusinessModules/Features/Procurement/migrations/'
            .'2026_05_03_000002_create_supplier_proposal_versions.php',
        );

        self::assertStringContainsString('static::updating', $model);
        self::assertStringContainsString('static::deleting', $model);
        self::assertStringContainsString("'supplier_proposal_versions'", $migration);
        self::assertStringNotContainsString('chunkById', $legacyMigration);
    }

    public function test_supply_owner_lifecycle_is_exact_and_reconciled(): void
    {
        $migration = $this->source(
            'app/BusinessModules/Features/Procurement/migrations/'
            .'2026_07_26_120000_create_supply_reliability_reporting_tables.php',
        );
        $readiness = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/Supply/Readiness/'
            .'SupplyReliabilityReadinessProbe.php',
        );
        $service = $this->source(
            'app/BusinessModules/Features/Procurement/Services/PurchaseOrderService.php',
        );
        $lifecycle = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/'
            .'ProcurementReportingLifecycleRecorder.php',
        );
        foreach ([
            'source_order.confirmed_at IS NULL',
            'source_order.cancelled_at IS NULL',
            'NEW.signed_quantity <> source_line.quantity_received',
            'NEW.signed_quantity <> -reversed_event.signed_quantity',
            'source_line.reversal_warehouse_movement_id IS NULL',
            'most_purchase_request_supply_identity_v1',
            'most_site_request_supply_identity_v1',
        ] as $fence) {
            self::assertStringContainsString($fence, $migration);
        }
        foreach ([
            'missingPromise',
            'missingReceiptLifecycle',
            'missingReversalLifecycle',
        ] as $reconciliation) {
            self::assertStringContainsString($reconciliation, $readiness);
        }
        self::assertStringContainsString('orderCancelled(', $lifecycle);
        self::assertLessThan(
            strpos($service, '$this->reportingLifecycle->receiptReversed('),
            strpos($service, "'reversal_idempotency_key' => \$idempotencyKey"),
        );
    }

    private function source(string $path): string
    {
        $source = file_get_contents($this->root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path));
        self::assertIsString($source, $path);

        return $source;
    }
}
