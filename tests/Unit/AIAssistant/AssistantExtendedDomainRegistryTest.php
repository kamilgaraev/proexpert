<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Observers\AssistantRagEntityObserver;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AssistantCapabilityRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantFinanceTenderMetadata;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantWorkforceCatalogMetadata;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\VideoMonitoringAssistantMetadata;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\GlobalRagQueue;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use PHPUnit\Framework\TestCase;

final class AssistantExtendedDomainRegistryTest extends TestCase
{
    public function test_business_inventory_is_complete_and_registered_for_current_read_operations(): void
    {
        $this->assertCount(48, AssistantFinanceTenderMetadata::entityDefinitions());
        $this->assertCount(42, AssistantWorkforceCatalogMetadata::entityDefinitions());
        $this->assertCount(4, VideoMonitoringAssistantMetadata::entityDefinitions());
        $catalog = new AssistantDomainCatalog(AssistantDomainCatalog::defaults());
        foreach (['design', 'budgeting', 'tenders', 'advance_accounting', 'workforce', 'workforce_hr', 'workforce_payroll',
            'brigades', 'materials', 'work_types', 'measurement_units', 'video_monitoring', 'one_c_exchange', 'report_templates'] as $domain) {
            $definition = $catalog->definition($domain);
            $this->assertNotNull($definition, $domain);
            foreach (['read', 'search', 'navigation'] as $operation) { $this->assertTrue($catalog->supports($domain, $operation), $domain.'.'.$operation); }
        }
        $this->assertContains('design_artifact_version', $catalog->definition('design')->entityTypes);
        $this->assertSame(['design', 'design_package'], AssistantRagEntityObserver::definitions()[DesignPackage::class]);
        foreach (AssistantExtendedDomainRegistry::values('sourceClasses') as $class) {
            $this->assertTrue(is_subclass_of($class, RagSourceCollectorInterface::class), $class);
        }
    }

    public function test_required_parent_and_content_permissions_survive_registry_merge(): void
    {
        $parents = AssistantExtendedDomainRegistry::values('parentColumns');
        $this->assertFalse($parents['video_camera_event']['camera_id']['nullable']);
        $this->assertSame('video_camera', $parents['video_camera_event']['camera_id']['type']);
        $this->assertSame('close_id', $parents['budgeting_report_source_watermark_record']['close_id']['key']);
        $this->assertSame(['tender_id' => 'tender_id'], $parents['tender_deadline_reminder']['deadline_id']['matches']);
        $this->assertContains('video-monitoring.events.view', AssistantExtendedDomainRegistry::values('entityPermissions')['video_camera_event']);
        $this->assertContains('workforce.payroll-source.manage', AssistantExtendedDomainRegistry::values('sourcePermissions')['workforce_payroll']);
        $this->assertContains('finance.view', AssistantExtendedDomainRegistry::values('sourcePermissions')['workforce_payroll']);
        $columns = AssistantExtendedDomainRegistry::values('safeSelectColumns')['video_camera'];
        foreach (['source_url', 'playback_url', 'password', 'username', 'host', 'port', 'settings'] as $secret) { $this->assertNotContains($secret, $columns); }
        $this->assertSame('project_id', AssistantExtendedDomainRegistry::values('organizationAggregates')['wip_forecast_version']);
    }

    public function test_declared_metadata_cache_does_not_allow_a_caller_to_change_permission_or_parent_maps(): void
    {
        $permissions = AssistantExtendedDomainRegistry::values('entityPermissions');
        $permissions['video_camera_event'] = [];
        $parents = AssistantExtendedDomainRegistry::values('parentColumns');
        $parents['video_camera_event']['camera_id']['nullable'] = true;
        $this->assertContains('video-monitoring.events.view', AssistantExtendedDomainRegistry::values('entityPermissions')['video_camera_event']);
        $this->assertFalse(AssistantExtendedDomainRegistry::values('parentColumns')['video_camera_event']['camera_id']['nullable']);
    }

    public function test_extended_domains_are_discoverable_and_only_known_global_categories_fan_out(): void
    {
        $registry = new AssistantCapabilityRegistry;
        foreach (['design', 'budgeting', 'tenders', 'advance_accounting', 'workforce', 'workforce_hr', 'workforce_payroll',
            'brigades', 'materials', 'work_types', 'measurement_units', 'video_monitoring', 'one_c_exchange', 'report_templates'] as $domain) {
            $this->assertNotEmpty($registry->domainKeywords($domain), $domain);
        }
        $this->assertTrue(GlobalRagQueue::supports('tenders', 'tender_source', '12345678-1234-4123-8123-123456789012'));
        $this->assertTrue(GlobalRagQueue::supports('brigades', 'brigade_specialization', 7));
        $this->assertTrue(GlobalRagQueue::supports('brigades', 'brigade_specialization_link', 9));
        foreach (['brigade_request', 'brigade_assignment', 'brigade_invitation', 'brigade_response'] as $type) {
            $this->assertFalse(GlobalRagQueue::supports('brigades', $type, 7), $type);
        }
        $this->assertFalse(GlobalRagQueue::supports('payment', 'tender_source', '12345678-1234-4123-8123-123456789012'));
    }
}
