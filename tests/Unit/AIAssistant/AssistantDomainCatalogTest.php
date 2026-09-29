<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use PHPUnit\Framework\TestCase;

final class AssistantDomainCatalogTest extends TestCase
{
    public function test_all_business_domains_have_closed_search_read_and_navigation_schemas(): void
    {
        $catalog = new AssistantDomainCatalog(AssistantDomainCatalog::defaults());
        foreach (['projects','estimates','contracts','finance','procurement','schedule','warehouse','works','acts','people','production_labor','time_tracking','machinery','quality','documents','safety','change_management','handover_acceptance','crm','commercial_processes','knowledge'] as $domain) {
            $definition = $catalog->definition($domain);
            $this->assertNotNull($definition, $domain);
            foreach (['search','read','navigation'] as $operation) {
                $schema = $definition->schema($operation);
                $this->assertSame(false, $schema['additionalProperties']);
                $this->assertSame(array_keys($schema['properties']), $schema['required']);
                $this->assertContains($definition->entityType, $schema['properties']['entity_type']['enum']);
            }
        }
        $this->assertFalse($catalog->supports('unknown', 'read'));
        $this->assertFalse($catalog->supports('estimates', 'delete'));
    }

    public function test_money_fields_and_crm_entities_have_separate_real_permissions(): void
    {
        $catalog = new AssistantDomainCatalog(AssistantDomainCatalog::defaults());
        $estimates = $catalog->definition('estimates');
        $this->assertSame('budget-estimates.finance.view', $estimates->fieldPermissions['total_amount']);
        $this->assertSame('crm.deals.view', $catalog->definition('crm')->entityPermissions['crm_deal']);
        $this->assertContains('estimate_item_resource', $estimates->entityTypes);
        $this->assertSame('/projects/{id}', $catalog->definition('projects')->navigation);
        $this->assertSame('/knowledge-hub/articles/{slug}', $catalog->definition('knowledge')->navigation);
    }
}
