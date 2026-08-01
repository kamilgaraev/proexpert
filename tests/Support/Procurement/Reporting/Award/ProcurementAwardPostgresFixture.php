<?php

declare(strict_types=1);

namespace Tests\Support\Procurement\Reporting\Award;

use Illuminate\Database\Connection;

final class ProcurementAwardPostgresFixture
{
    public function __construct(private readonly Connection $connection) {}

    public function create(string $namespace): array
    {
        return $this->connection->transaction(function () use ($namespace): array {
            $now = '2026-08-01 09:00:00+00';
            $userId = (int) $this->connection->table('users')->insertGetId([
                'name' => "Award {$namespace}",
                'email' => "award-{$namespace}@example.test",
                'password' => 'not-used-by-contract-tests',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $organizationId = (int) $this->connection->table('organizations')->insertGetId([
                'name' => "Award {$namespace}",
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $projectId = (int) $this->connection->table('projects')->insertGetId([
                'organization_id' => $organizationId,
                'name' => "Award project {$namespace}",
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $siteRequestId = (int) $this->connection->table('site_requests')->insertGetId([
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'user_id' => $userId,
                'title' => "Award site request {$namespace}",
                'status' => 'approved',
                'priority' => 'medium',
                'request_type' => 'material',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $purchaseRequestId = (int) $this->connection->table('purchase_requests')->insertGetId([
                'organization_id' => $organizationId,
                'site_request_id' => $siteRequestId,
                'request_number' => "PR-AWARD-{$namespace}",
                'status' => 'approved',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $purchaseRequestLineId = (int) $this->connection->table('purchase_request_lines')->insertGetId([
                'purchase_request_id' => $purchaseRequestId,
                'name' => 'Award material',
                'quantity' => 1,
                'unit' => 'pcs',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $primary = $this->supplierRequest(
                $namespace,
                'primary',
                $organizationId,
                $purchaseRequestId,
                $purchaseRequestLineId,
                $now,
            );
            $first = $this->proposal($namespace, 'first', $primary, $organizationId, 100, $now);
            $second = $this->proposal($namespace, 'second', $primary, $organizationId, 120, $now);
            $foreign = $this->supplierRequest(
                $namespace,
                'foreign',
                $organizationId,
                $purchaseRequestId,
                $purchaseRequestLineId,
                $now,
            );
            $foreignProposal = $this->proposal($namespace, 'foreign', $foreign, $organizationId, 90, $now);

            $decisionId = (int) $this->connection->table('supplier_proposal_decisions')->insertGetId([
                'organization_id' => $organizationId,
                'supplier_request_id' => $primary['supplier_request_id'],
                'winning_supplier_proposal_id' => $first['proposal_id'],
                'winning_supplier_proposal_version_id' => $first['proposal_version_id'],
                'cheapest_supplier_proposal_id' => $first['proposal_id'],
                'cheapest_supplier_proposal_version_id' => $first['proposal_version_id'],
                'status' => 'selected',
                'is_lowest_price_selected' => true,
                'comparison_snapshot' => json_encode([], JSON_THROW_ON_ERROR),
                'selected_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $orderId = (int) $this->connection->table('purchase_orders')->insertGetId([
                'organization_id' => $organizationId,
                'purchase_request_id' => $purchaseRequestId,
                'accepted_supplier_proposal_id' => $first['proposal_id'],
                'accepted_supplier_proposal_version_id' => $first['proposal_version_id'],
                'supplier_id' => $primary['supplier_id'],
                'supplier_party_id' => $primary['supplier_party_id'],
                'supplier_snapshot' => json_encode(['id' => $primary['supplier_party_id']], JSON_THROW_ON_ERROR),
                'order_number' => "PO-AWARD-{$namespace}",
                'order_date' => '2026-08-01',
                'status' => 'sent',
                'total_amount' => 100,
                'currency' => 'RUB',
                'pricing_source' => 'accepted_supplier_proposal',
                'sent_at' => '2026-08-01',
                'sent_at_exact' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return [
                'user_id' => $userId,
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'purchase_request_id' => $purchaseRequestId,
                'purchase_request_line_id' => $purchaseRequestLineId,
                'supplier_request' => $primary,
                'first' => $first,
                'second' => $second,
                'foreign' => $foreignProposal,
                'decision_id' => $decisionId,
                'purchase_order_id' => $orderId,
            ];
        });
    }

    private function supplierRequest(
        string $namespace,
        string $suffix,
        int $organizationId,
        int $purchaseRequestId,
        int $purchaseRequestLineId,
        string $now,
    ): array {
        $supplierId = (int) $this->connection->table('suppliers')->insertGetId([
            'organization_id' => $organizationId,
            'name' => "Award supplier {$namespace} {$suffix}",
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $partyId = (int) $this->connection->table('supplier_parties')->insertGetId([
            'organization_id' => $organizationId,
            'type' => 'registered',
            'status' => 'linked',
            'registered_supplier_id' => $supplierId,
            'display_name' => "Award party {$namespace} {$suffix}",
            'snapshot' => json_encode(['type' => 'registered'], JSON_THROW_ON_ERROR),
            'linked_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $requestId = (int) $this->connection->table('supplier_requests')->insertGetId([
            'organization_id' => $organizationId,
            'purchase_request_id' => $purchaseRequestId,
            'supplier_id' => $supplierId,
            'supplier_party_id' => $partyId,
            'supplier_snapshot' => json_encode(['id' => $partyId], JSON_THROW_ON_ERROR),
            'request_number' => "SR-AWARD-{$namespace}-{$suffix}",
            'status' => 'responded',
            'sent_at' => $now,
            'responded_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $lineId = (int) $this->connection->table('supplier_request_lines')->insertGetId([
            'supplier_request_id' => $requestId,
            'purchase_request_line_id' => $purchaseRequestLineId,
            'name' => 'Award material',
            'quantity' => 1,
            'unit' => 'pcs',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $requestHash = hash('sha256', "request:{$namespace}:{$suffix}");
        $versionId = (int) $this->connection->table('supplier_request_versions')->insertGetId([
            'organization_id' => $organizationId,
            'supplier_request_id' => $requestId,
            'version_number' => 1,
            'request_snapshot' => json_encode(['request_id' => $requestId], JSON_THROW_ON_ERROR),
            'line_snapshot' => json_encode([], JSON_THROW_ON_ERROR),
            'supplier_snapshot' => json_encode(['id' => $partyId], JSON_THROW_ON_ERROR),
            'sent_at' => $now,
            'content_hash' => $requestHash,
            'integrity_status' => 'verified',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'supplier_id' => $supplierId,
            'supplier_party_id' => $partyId,
            'supplier_request_id' => $requestId,
            'supplier_request_line_id' => $lineId,
            'supplier_request_version_id' => $versionId,
            'supplier_request_version_hash' => $requestHash,
        ];
    }

    private function proposal(
        string $namespace,
        string $suffix,
        array $request,
        int $organizationId,
        int $total,
        string $now,
    ): array {
        $proposalId = (int) $this->connection->table('supplier_proposals')->insertGetId([
            'organization_id' => $organizationId,
            'supplier_request_id' => $request['supplier_request_id'],
            'supplier_request_version_id' => $request['supplier_request_version_id'],
            'supplier_id' => $request['supplier_id'],
            'supplier_party_id' => $request['supplier_party_id'],
            'supplier_snapshot' => json_encode(['id' => $request['supplier_party_id']], JSON_THROW_ON_ERROR),
            'proposal_number' => "SP-AWARD-{$namespace}-{$suffix}",
            'proposal_date' => '2026-08-01',
            'status' => 'accepted',
            'subtotal_amount' => $total,
            'delivery_amount' => 0,
            'vat_amount' => 0,
            'total_amount' => $total,
            'currency' => 'RUB',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->connection->table('supplier_proposal_lines')->insert([
            'supplier_proposal_id' => $proposalId,
            'supplier_request_line_id' => $request['supplier_request_line_id'],
            'name' => 'Award material',
            'quantity' => 1,
            'unit' => 'pcs',
            'unit_price' => $total,
            'total_amount' => $total,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $versionHash = hash('sha256', "proposal:{$namespace}:{$suffix}");
        $versionId = (int) $this->connection->table('supplier_proposal_versions')->insertGetId([
            'organization_id' => $organizationId,
            'supplier_proposal_id' => $proposalId,
            'version_number' => 1,
            'commercial_snapshot' => json_encode(['total_amount' => $total], JSON_THROW_ON_ERROR),
            'attachment_snapshot' => json_encode([], JSON_THROW_ON_ERROR),
            'content_hash' => $versionHash,
            'integrity_status' => 'verified',
            'created_at' => $now,
        ]);

        return [
            ...$request,
            'proposal_id' => $proposalId,
            'proposal_version_id' => $versionId,
            'version_content_hash' => $versionHash,
            'total' => (string) $total,
        ];
    }
}
