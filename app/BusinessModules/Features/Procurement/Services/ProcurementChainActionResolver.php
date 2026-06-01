<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\DTOs\ProcurementChainAction;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;

use function trans_message;

final class ProcurementChainActionResolver
{
    /**
     * @var array<string, string>
     */
    private const PERMISSIONS = [
        'approve_site_request' => 'site_requests.approve',
        'create_purchase_request' => 'procurement.purchase_requests.create',
        'approve_purchase_request' => 'procurement.purchase_requests.approve',
        'create_supplier_request' => 'procurement.supplier_requests.create',
        'send_supplier_request' => 'procurement.supplier_requests.send',
        'select_proposal' => 'procurement.proposal_decisions.select',
        'approve_proposal_selection' => 'procurement.approvals.resolve',
        'accept_proposal' => 'procurement.supplier_proposals.accept',
        'open_purchase_order' => 'procurement.purchase_orders.view',
        'create_or_open_payment_document' => 'payments.invoice.create',
        'register_payment' => 'payments.transaction.register',
        'receive_materials' => 'procurement.purchase_orders.receive',
        'open_warehouse_receipt' => 'warehouse.view',
    ];

    public function __construct(
        private readonly AuthorizationService $authorizationService
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function action(
        string $key,
        ?string $href,
        ?User $actor,
        int $organizationId,
        array $context = [],
        string $method = 'GET',
        bool $domainEnabled = true,
        ?string $domainDisabledReason = null
    ): ProcurementChainAction {
        $permission = self::PERMISSIONS[$key] ?? null;
        $hasPermission = $permission === null || $actor === null || $this->authorizationService->can($actor, $permission, [
            'organization_id' => $organizationId,
        ]);
        $isEnabled = $domainEnabled && $hasPermission;
        $disabledReason = null;

        if (! $domainEnabled) {
            $disabledReason = $domainDisabledReason ?? trans_message('procurement.chain.actions.disabled.waiting_external_step');
        } elseif (! $hasPermission) {
            $disabledReason = trans_message('procurement.chain.actions.disabled.permission_missing');
        }

        return new ProcurementChainAction(
            key: $key,
            label: trans_message("procurement.chain.actions.{$key}"),
            href: $href,
            method: $method,
            requiredPermission: $permission,
            isEnabled: $isEnabled,
            disabledReason: $disabledReason,
        );
    }

    /**
     * @return array<string, bool>
     */
    public function permissions(?User $actor, int $organizationId): array
    {
        $permissions = [];

        foreach (array_unique(self::PERMISSIONS) as $permission) {
            $permissions[$permission] = $actor === null || $this->authorizationService->can($actor, $permission, [
                'organization_id' => $organizationId,
            ]);
        }

        return $permissions;
    }
}
