<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationSuppliersReader;

final readonly class GetOrganizationSupplierRosterTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_organization_supplier_roster';
    public const DESCRIPTION = 'ai_assistant_operational.supplier_description';
    public function __construct(AssistantOrganizationSuppliersReader $reader) { parent::__construct($reader); }
}
