<?php

declare(strict_types=1);

namespace Tests\Support\Procurement\Reporting;

use Illuminate\Database\Connection;

final class ProcurementCyclePostgresFixture
{
    public function __construct(private readonly Connection $connection) {}

    public function create(string $namespace): array
    {
        return $this->connection->transaction(function () use ($namespace): array {
            $now = '2026-08-01 09:00:00+00';
            $userId = (int) $this->connection->table('users')->insertGetId([
                'name' => "Procurement cycle {$namespace}",
                'email' => "procurement-cycle-{$namespace}@example.test",
                'password' => 'not-used-by-contract-tests',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $organizationA = $this->organization("{$namespace}-org-a", $now);
            $organizationB = $this->organization("{$namespace}-org-b", $now);
            $projectA = $this->project($organizationA, "{$namespace}-project-a", $now);
            $projectA2 = $this->project($organizationA, "{$namespace}-project-a2", $now);
            $projectB = $this->project($organizationB, "{$namespace}-project-b", $now);
            $warehouseA = $this->warehouse($organizationA, "{$namespace}-warehouse-a", $now);
            $warehouseB = $this->warehouse($organizationB, "{$namespace}-warehouse-b", $now);

            $policyA = $this->policy(
                $organizationA,
                $projectA,
                1,
                str_repeat('a', 64),
                str_repeat('1', 64),
                ['request_cancelled'],
                $now,
            );
            $policyA2 = $this->policy(
                $organizationA,
                $projectA2,
                1,
                str_repeat('b', 64),
                str_repeat('2', 64),
                ['request_rejected'],
                $now,
            );
            $policyAOrganization = $this->policy(
                $organizationA,
                null,
                2,
                str_repeat('c', 64),
                str_repeat('3', 64),
                ['request_cancelled', 'order_cancelled'],
                $now,
            );
            $policyB = $this->policy(
                $organizationB,
                $projectB,
                1,
                str_repeat('d', 64),
                str_repeat('4', 64),
                ['request_cancelled'],
                $now,
            );

            $chainA = $this->chain(
                $namespace,
                'a',
                $organizationA,
                $projectA,
                $warehouseA,
                $userId,
                $policyA,
                $now,
            );
            $chainA2 = $this->chain(
                $namespace,
                'a2',
                $organizationA,
                $projectA2,
                $warehouseA,
                $userId,
                $policyA2,
                $now,
            );
            $chainB = $this->chain(
                $namespace,
                'b',
                $organizationB,
                $projectB,
                $warehouseB,
                $userId,
                $policyB,
                $now,
            );
            $directOrder = $this->directOrderChain(
                $namespace,
                $organizationA,
                $projectA,
                $userId,
                $policyA,
                $now,
            );
            $noProject = $this->requestLine(
                $namespace,
                'no-project',
                $organizationA,
                null,
                $userId,
                $now,
            );

            $crossedProposalPartyId = (int) $this->connection->table('supplier_proposals')->insertGetId([
                'organization_id' => $organizationA,
                'supplier_request_id' => $chainA['supplier_request_id'],
                'supplier_id' => $chainA2['supplier_id'],
                'supplier_party_id' => $chainA2['supplier_party_id'],
                'supplier_snapshot' => json_encode(['id' => $chainA2['supplier_party_id']], JSON_THROW_ON_ERROR),
                'proposal_number' => "SP-{$namespace}-crossed-party",
                'proposal_date' => '2026-08-01',
                'status' => 'submitted',
                'subtotal_amount' => 10,
                'delivery_amount' => 0,
                'vat_amount' => 0,
                'total_amount' => 10,
                'currency' => 'RUB',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $crossedOrderPartyId = (int) $this->connection->table('purchase_orders')->insertGetId([
                'organization_id' => $organizationA,
                'purchase_request_id' => $chainA['purchase_request_id'],
                'accepted_supplier_proposal_id' => $chainA['supplier_proposal_id'],
                'accepted_supplier_proposal_version_id' => $chainA['supplier_proposal_version_id'],
                'supplier_id' => $chainA2['supplier_id'],
                'supplier_party_id' => $chainA2['supplier_party_id'],
                'supplier_snapshot' => json_encode(['id' => $chainA2['supplier_party_id']], JSON_THROW_ON_ERROR),
                'order_number' => "PO-{$namespace}-crossed-party",
                'order_date' => '2026-08-01',
                'status' => 'sent',
                'total_amount' => 10,
                'currency' => 'RUB',
                'pricing_source' => 'accepted_supplier_proposal',
                'sent_at' => '2026-08-01',
                'sent_at_exact' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $crossedOrderItemId = (int) $this->connection->table('purchase_order_items')->insertGetId([
                'purchase_order_id' => $chainA['purchase_order_id'],
                'purchase_request_line_id' => $chainA['purchase_request_line_id'],
                'supplier_request_line_id' => $chainA['supplier_request_line_id'],
                'supplier_proposal_line_id' => $chainA2['supplier_proposal_line_id'],
                'material_name' => 'Crossed lineage item',
                'quantity' => 1,
                'unit' => 'pcs',
                'unit_price' => 10,
                'total_price' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return [
                'namespace' => $namespace,
                'user_id' => $userId,
                'organization_a_id' => $organizationA,
                'organization_b_id' => $organizationB,
                'project_a_id' => $projectA,
                'project_a2_id' => $projectA2,
                'project_b_id' => $projectB,
                'warehouse_a_id' => $warehouseA,
                'warehouse_b_id' => $warehouseB,
                'policy_a' => $policyA,
                'policy_a2' => $policyA2,
                'policy_a_organization' => $policyAOrganization,
                'policy_b' => $policyB,
                'a' => $chainA,
                'a2' => $chainA2,
                'b' => $chainB,
                'direct_order' => $directOrder,
                'no_project' => $noProject,
                'crossed_proposal_party_id' => $crossedProposalPartyId,
                'crossed_order_party_id' => $crossedOrderPartyId,
                'crossed_order_item_id' => $crossedOrderItemId,
            ];
        });
    }

    public function cleanup(array $fixture): void
    {
        $organizations = [
            $fixture['organization_a_id'],
            $fixture['organization_b_id'],
        ];

        $this->connection->transaction(function () use ($fixture, $organizations): void {
            $this->connection->unprepared(
                'ALTER TABLE procurement_process_events '
                .'DISABLE TRIGGER procurement_process_events_append_only',
            );
            $this->connection->unprepared(
                'ALTER TABLE procurement_cycle_policy_versions '
                .'DISABLE TRIGGER procurement_cycle_policy_versions_append_only',
            );

            try {
                $this->connection->table('procurement_process_events')
                    ->whereIn('organization_id', $organizations)
                    ->delete();
                $this->connection->table('procurement_cycle_policy_versions')
                    ->whereIn('organization_id', $organizations)
                    ->delete();
            } finally {
                $this->connection->unprepared(
                    'ALTER TABLE procurement_process_events '
                    .'ENABLE TRIGGER procurement_process_events_append_only',
                );
                $this->connection->unprepared(
                    'ALTER TABLE procurement_cycle_policy_versions '
                    .'ENABLE TRIGGER procurement_cycle_policy_versions_append_only',
                );
            }

            $this->connection->table('purchase_receipt_lines')
                ->whereIn('purchase_receipt_id', function ($query) use ($organizations): void {
                    $query->select('id')->from('purchase_receipts')->whereIn('organization_id', $organizations);
                })->delete();
            $this->connection->table('purchase_receipts')->whereIn('organization_id', $organizations)->delete();
            $this->connection->table('purchase_order_items')
                ->whereIn('purchase_order_id', function ($query) use ($organizations): void {
                    $query->select('id')->from('purchase_orders')->whereIn('organization_id', $organizations);
                })->delete();
            $this->connection->table('purchase_orders')->whereIn('organization_id', $organizations)->delete();
            $this->connection->table('supplier_proposal_decisions')
                ->whereIn('organization_id', $organizations)->delete();
            $this->connection->table('supplier_proposal_versions')
                ->whereIn('organization_id', $organizations)->delete();
            $this->connection->table('supplier_proposal_lines')
                ->whereIn('supplier_proposal_id', function ($query) use ($organizations): void {
                    $query->select('id')->from('supplier_proposals')->whereIn('organization_id', $organizations);
                })->delete();
            $this->connection->table('supplier_proposals')->whereIn('organization_id', $organizations)->delete();
            $this->connection->table('supplier_request_lines')
                ->whereIn('supplier_request_id', function ($query) use ($organizations): void {
                    $query->select('id')->from('supplier_requests')->whereIn('organization_id', $organizations);
                })->delete();
            $this->connection->table('supplier_requests')->whereIn('organization_id', $organizations)->delete();
            $this->connection->table('supplier_parties')->whereIn('organization_id', $organizations)->delete();
            $this->connection->table('suppliers')->whereIn('organization_id', $organizations)->delete();
            $this->connection->table('purchase_request_lines')
                ->whereIn('purchase_request_id', function ($query) use ($organizations): void {
                    $query->select('id')->from('purchase_requests')->whereIn('organization_id', $organizations);
                })->delete();
            $this->connection->table('purchase_requests')->whereIn('organization_id', $organizations)->delete();
            $this->connection->table('site_requests')->whereIn('organization_id', $organizations)->delete();
            $this->connection->table('organization_warehouses')->whereIn('organization_id', $organizations)->delete();
            $this->connection->table('projects')->whereIn('organization_id', $organizations)->delete();
            $this->connection->table('organizations')->whereIn('id', $organizations)->delete();
            $this->connection->table('users')->where('id', $fixture['user_id'])->delete();
        });
    }

    private function organization(string $name, string $now): int
    {
        return (int) $this->connection->table('organizations')->insertGetId([
            'name' => $name,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function project(int $organizationId, string $name, string $now): int
    {
        return (int) $this->connection->table('projects')->insertGetId([
            'organization_id' => $organizationId,
            'name' => $name,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function warehouse(int $organizationId, string $code, string $now): int
    {
        return (int) $this->connection->table('organization_warehouses')->insertGetId([
            'organization_id' => $organizationId,
            'name' => $code,
            'code' => $code,
            'warehouse_type' => 'central',
            'is_main' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function policy(
        int $organizationId,
        ?int $projectId,
        int $versionNumber,
        string $canonicalHash,
        string $calendarHash,
        array $terminalReasons,
        string $now,
    ): array {
        $id = (int) $this->connection->table('procurement_cycle_policy_versions')->insertGetId([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'version_number' => $versionNumber,
            'formula_version' => 'procurement-cycle.v1',
            'source_schema_version' => '1.0.0',
            'event_schema_version' => 'procurement-process-events.v1',
            'calendar_version' => 'procurement-business-calendar.v1',
            'calendar_hash' => $calendarHash,
            'timezone' => 'UTC',
            'weekly_windows' => json_encode(['mon' => [['00:00', '23:59']]], JSON_THROW_ON_ERROR),
            'exceptions' => json_encode([], JSON_THROW_ON_ERROR),
            'stage_sla_seconds' => json_encode(['request_created' => 3600], JSON_THROW_ON_ERROR),
            'total_sla_seconds' => 3600,
            'terminal_cancellation_policy' => json_encode($terminalReasons, JSON_THROW_ON_ERROR),
            'effective_from' => '2026-01-01 00:00:00+00',
            'effective_to' => null,
            'canonical_hash' => $canonicalHash,
            'published_by' => null,
            'published_at' => $now,
            'created_at' => $now,
        ]);

        return [
            'id' => $id,
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'hash' => $canonicalHash,
            'calendar_version' => 'procurement-business-calendar.v1',
            'calendar_hash' => $calendarHash,
            'terminal_reasons' => $terminalReasons,
        ];
    }

    private function chain(
        string $namespace,
        string $suffix,
        int $organizationId,
        int $projectId,
        int $warehouseId,
        int $userId,
        array $policy,
        string $now,
    ): array
    {
        $base = $this->requestLine($namespace, $suffix, $organizationId, $projectId, $userId, $now);
        $supplierId = (int) $this->connection->table('suppliers')->insertGetId([
            'organization_id' => $organizationId,
            'name' => "Supplier {$namespace} {$suffix}",
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $supplierPartyId = (int) $this->connection->table('supplier_parties')->insertGetId([
            'organization_id' => $organizationId,
            'type' => 'registered',
            'status' => 'linked',
            'registered_supplier_id' => $supplierId,
            'display_name' => "Supplier party {$namespace} {$suffix}",
            'snapshot' => json_encode(['type' => 'registered'], JSON_THROW_ON_ERROR),
            'linked_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $supplierRequestId = (int) $this->connection->table('supplier_requests')->insertGetId([
            'organization_id' => $organizationId,
            'purchase_request_id' => $base['purchase_request_id'],
            'supplier_id' => $supplierId,
            'supplier_party_id' => $supplierPartyId,
            'supplier_snapshot' => json_encode(['id' => $supplierPartyId], JSON_THROW_ON_ERROR),
            'request_number' => "SR-{$namespace}-{$suffix}",
            'status' => 'responded',
            'sent_at' => $now,
            'responded_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $supplierRequestLineId = (int) $this->connection->table('supplier_request_lines')->insertGetId([
            'supplier_request_id' => $supplierRequestId,
            'purchase_request_line_id' => $base['purchase_request_line_id'],
            'name' => "Requested material {$suffix}",
            'quantity' => 1,
            'unit' => 'pcs',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $proposalId = (int) $this->connection->table('supplier_proposals')->insertGetId([
            'organization_id' => $organizationId,
            'supplier_request_id' => $supplierRequestId,
            'supplier_id' => $supplierId,
            'supplier_party_id' => $supplierPartyId,
            'supplier_snapshot' => json_encode(['id' => $supplierPartyId], JSON_THROW_ON_ERROR),
            'proposal_number' => "SP-{$namespace}-{$suffix}",
            'proposal_date' => '2026-08-01',
            'status' => 'accepted',
            'subtotal_amount' => 10,
            'delivery_amount' => 0,
            'vat_amount' => 0,
            'total_amount' => 10,
            'currency' => 'RUB',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $proposalLineId = (int) $this->connection->table('supplier_proposal_lines')->insertGetId([
            'supplier_proposal_id' => $proposalId,
            'supplier_request_line_id' => $supplierRequestLineId,
            'name' => "Proposed material {$suffix}",
            'quantity' => 1,
            'unit' => 'pcs',
            'unit_price' => 10,
            'total_amount' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $proposalVersionId = (int) $this->connection->table('supplier_proposal_versions')->insertGetId([
            'organization_id' => $organizationId,
            'supplier_proposal_id' => $proposalId,
            'version_number' => 1,
            'commercial_snapshot' => json_encode(['total_amount' => 10], JSON_THROW_ON_ERROR),
            'attachment_snapshot' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ]);
        $decisionId = (int) $this->connection->table('supplier_proposal_decisions')->insertGetId([
            'organization_id' => $organizationId,
            'supplier_request_id' => $supplierRequestId,
            'winning_supplier_proposal_id' => $proposalId,
            'winning_supplier_proposal_version_id' => $proposalVersionId,
            'cheapest_supplier_proposal_id' => $proposalId,
            'cheapest_supplier_proposal_version_id' => $proposalVersionId,
            'status' => 'selected',
            'is_lowest_price_selected' => true,
            'comparison_snapshot' => json_encode([], JSON_THROW_ON_ERROR),
            'selected_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $orderId = (int) $this->connection->table('purchase_orders')->insertGetId([
            'organization_id' => $organizationId,
            'purchase_request_id' => $base['purchase_request_id'],
            'accepted_supplier_proposal_id' => $proposalId,
            'accepted_supplier_proposal_version_id' => $proposalVersionId,
            'supplier_id' => $supplierId,
            'supplier_party_id' => $supplierPartyId,
            'supplier_snapshot' => json_encode(['id' => $supplierPartyId], JSON_THROW_ON_ERROR),
            'order_number' => "PO-{$namespace}-{$suffix}",
            'order_date' => '2026-08-01',
            'status' => 'sent',
            'total_amount' => 10,
            'currency' => 'RUB',
            'pricing_source' => 'accepted_supplier_proposal',
            'sent_at' => '2026-08-01',
            'sent_at_exact' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $orderItemId = (int) $this->connection->table('purchase_order_items')->insertGetId([
            'purchase_order_id' => $orderId,
            'purchase_request_line_id' => $base['purchase_request_line_id'],
            'supplier_request_line_id' => $supplierRequestLineId,
            'supplier_proposal_line_id' => $proposalLineId,
            'material_name' => "Ordered material {$suffix}",
            'quantity' => 1,
            'unit' => 'pcs',
            'unit_price' => 10,
            'total_price' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $receiptId = (int) $this->connection->table('purchase_receipts')->insertGetId([
            'organization_id' => $organizationId,
            'purchase_order_id' => $orderId,
            'warehouse_id' => $warehouseId,
            'receipt_number' => "RC-{$namespace}-{$suffix}",
            'receipt_date' => '2026-08-01',
            'status' => 'posted',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $receiptLineId = (int) $this->connection->table('purchase_receipt_lines')->insertGetId([
            'purchase_receipt_id' => $receiptId,
            'purchase_order_item_id' => $orderItemId,
            'quantity_received' => 1,
            'price' => 10,
            'total_amount' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            ...$base,
            'supplier_id' => $supplierId,
            'supplier_party_id' => $supplierPartyId,
            'supplier_request_id' => $supplierRequestId,
            'supplier_request_line_id' => $supplierRequestLineId,
            'supplier_proposal_id' => $proposalId,
            'supplier_proposal_line_id' => $proposalLineId,
            'supplier_proposal_version_id' => $proposalVersionId,
            'supplier_proposal_decision_id' => $decisionId,
            'purchase_order_id' => $orderId,
            'purchase_order_item_id' => $orderItemId,
            'purchase_receipt_id' => $receiptId,
            'purchase_receipt_line_id' => $receiptLineId,
            'policy' => $policy,
        ];
    }

    private function requestLine(
        string $namespace,
        string $suffix,
        int $organizationId,
        ?int $projectId,
        int $userId,
        string $now,
    ): array {
        $siteRequestId = null;
        if ($projectId !== null) {
            $siteRequestId = (int) $this->connection->table('site_requests')->insertGetId([
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'user_id' => $userId,
                'title' => "Site request {$namespace} {$suffix}",
                'status' => 'approved',
                'priority' => 'medium',
                'request_type' => 'material',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $requestId = (int) $this->connection->table('purchase_requests')->insertGetId([
            'organization_id' => $organizationId,
            'site_request_id' => $siteRequestId,
            'request_number' => "PR-{$namespace}-{$suffix}",
            'status' => 'approved',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $lineId = (int) $this->connection->table('purchase_request_lines')->insertGetId([
            'purchase_request_id' => $requestId,
            'name' => "Purchase material {$suffix}",
            'quantity' => 1,
            'unit' => 'pcs',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'site_request_id' => $siteRequestId,
            'purchase_request_id' => $requestId,
            'purchase_request_line_id' => $lineId,
        ];
    }

    private function directOrderChain(
        string $namespace,
        int $organizationId,
        int $projectId,
        int $userId,
        array $policy,
        string $now,
    ): array {
        $base = $this->requestLine(
            $namespace,
            'direct',
            $organizationId,
            $projectId,
            $userId,
            $now,
        );
        $supplierId = (int) $this->connection->table('suppliers')->insertGetId([
            'organization_id' => $organizationId,
            'name' => "Supplier {$namespace} direct",
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $supplierPartyId = (int) $this->connection->table('supplier_parties')->insertGetId([
            'organization_id' => $organizationId,
            'type' => 'registered',
            'status' => 'linked',
            'registered_supplier_id' => $supplierId,
            'display_name' => "Supplier party {$namespace} direct",
            'snapshot' => json_encode(['type' => 'registered'], JSON_THROW_ON_ERROR),
            'linked_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $orderId = (int) $this->connection->table('purchase_orders')->insertGetId([
            'organization_id' => $organizationId,
            'purchase_request_id' => $base['purchase_request_id'],
            'accepted_supplier_proposal_id' => null,
            'accepted_supplier_proposal_version_id' => null,
            'supplier_id' => $supplierId,
            'supplier_party_id' => $supplierPartyId,
            'supplier_snapshot' => json_encode(['id' => $supplierPartyId], JSON_THROW_ON_ERROR),
            'order_number' => "PO-{$namespace}-direct",
            'order_date' => '2026-08-01',
            'status' => 'sent',
            'total_amount' => 10,
            'currency' => 'RUB',
            'pricing_source' => 'manual',
            'sent_at' => '2026-08-01',
            'sent_at_exact' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $orderItemId = (int) $this->connection->table('purchase_order_items')->insertGetId([
            'purchase_order_id' => $orderId,
            'purchase_request_line_id' => $base['purchase_request_line_id'],
            'supplier_request_line_id' => null,
            'supplier_proposal_line_id' => null,
            'material_name' => 'Direct ordered material',
            'quantity' => 1,
            'unit' => 'pcs',
            'unit_price' => 10,
            'total_price' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            ...$base,
            'supplier_id' => $supplierId,
            'supplier_party_id' => $supplierPartyId,
            'supplier_request_id' => null,
            'supplier_request_line_id' => null,
            'supplier_proposal_id' => null,
            'supplier_proposal_line_id' => null,
            'supplier_proposal_version_id' => null,
            'supplier_proposal_decision_id' => null,
            'purchase_order_id' => $orderId,
            'purchase_order_item_id' => $orderItemId,
            'purchase_receipt_id' => null,
            'purchase_receipt_line_id' => null,
            'policy' => $policy,
        ];
    }
}
