<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\AIAssistantModule;
use App\Models\Module;
use App\Services\Modules\PackageCatalogService;
use PHPUnit\Framework\TestCase;

final class AssistantBillingMetadataTest extends TestCase
{
    public function test_stored_legacy_allowance_is_retained_but_public_helper_limits_use_credits(): void
    {
        $module = new Module(['slug' => 'ai-assistant', 'limits' => ['max_ai_requests_per_month' => 7000, 'max_concurrent_chats' => 10]]);
        $public = $module->toPublicArray();

        $this->assertArrayNotHasKey('max_ai_requests_per_month', $public['limits']);
        $this->assertSame(10, $public['limits']['max_concurrent_chats']);
        $this->assertSame(7000, $module->limits['max_ai_requests_per_month']);
        $this->assertSame(5000, $public['assistant_billing']['included_units_per_paid_period']);
        $this->assertSame('organization_ai_credits', $public['assistant_billing']['limiting_resource']);
        $this->assertSame(2, $public['assistant_billing']['contract_version']);
    }

    public function test_other_module_limits_are_preserved(): void
    {
        $module = new Module(['slug' => 'ai-estimates', 'limits' => ['max_ai_requests_per_month' => 500]]);

        $this->assertSame($module->limits, $module->toPublicArray()['limits']);
        $this->assertArrayNotHasKey('assistant_billing', $module->toPublicArray());
    }

    public function test_current_package_and_helper_manifests_advertise_the_same_wallet_contract(): void
    {
        $root = dirname(__DIR__, 3);
        $catalog = new PackageCatalogService($root.'/config/Packages', $root.'/config/ModuleList');
        $package = $catalog->requirePackage('working-entry');
        $module = $catalog->moduleDefinitions()['ai-assistant'];
        $manifest = (new AIAssistantModule)->getManifest();

        $this->assertArrayNotHasKey('ai_requests_month', $package['limits']);
        $this->assertSame(500, $package['legacy_limits']['ai_requests_month']);
        $this->assertArrayNotHasKey('max_ai_requests_per_month', $module['limits']);
        $this->assertArrayNotHasKey('max_ai_requests_per_month', $manifest['limits']);
        $this->assertSame($module['assistant_billing'], $manifest['assistant_billing']);
        $this->assertSame($package['assistant_billing'], $manifest['assistant_billing']);
        $this->assertSame(5000, $module['legacy_limits']['max_ai_requests_per_month']);
    }
}
