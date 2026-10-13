<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationPurchaseOrdersReader;

final readonly class GetOrganizationPurchaseOrdersTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_organization_purchase_orders';

    public const DESCRIPTION = 'ai_assistant_operational.orders_description';

    public function __construct(AssistantOrganizationPurchaseOrdersReader $reader)
    {
        parent::__construct($reader);
    }
}
