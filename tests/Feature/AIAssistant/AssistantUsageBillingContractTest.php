<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\AIUsageStats;
use App\BusinessModules\Features\AIAssistant\Services\UsageTracker;
use App\Models\Organization;
use App\Models\Module;
use App\Modules\Core\ModuleScanner;
use App\Modules\Events\ModuleDiscovered;
use App\Services\Credits\AICreditService;
use Illuminate\Support\Facades\Event;
use ReflectionMethod;
use Tests\TestCase;

final class AssistantUsageBillingContractTest extends TestCase
{
    public function test_request_counter_above_legacy_allowance_does_not_block_a_funded_wallet(): void
    {
        config()->set('ai-assistant-credits.enforce', true);
        $organization = Organization::factory()->create();
        AIUsageStats::query()->create([
            'organization_id' => $organization->id,
            'year' => now()->year,
            'month' => now()->month,
            'requests_count' => 6000,
            'tokens_used' => 12345,
            'cost_rub' => 10,
        ]);
        app(AICreditService::class)->grant($organization, 10000, 'purchase', null, 'usage-contract-test');
        $tracker = new UsageTracker;

        $this->assertTrue($tracker->canMakeRequest((int) $organization->id));
        $stats = $tracker->getUsageStats((int) $organization->id);
        $this->assertSame(6000, $stats['used']);
        $this->assertSame(12345, $stats['tokens_used']);
        $this->assertNull($stats['monthly_limit']);
        $this->assertNull($stats['remaining']);
        $this->assertNull($stats['percentage_used']);
        $this->assertSame('statistics', $stats['usage_kind']);
        $this->assertSame('organization_ai_credits', $stats['limiting_resource']);
        $this->assertSame(2, $stats['billing_contract_version']);
        $this->assertFalse($tracker->canMakeRequest((int) Organization::factory()->create()->id));
    }

    public function test_module_rescan_preserves_custom_paid_legacy_allowance_without_publishing_a_request_limit(): void
    {
        Event::fake([ModuleDiscovered::class]);
        $config = json_decode((string) file_get_contents(config_path('ModuleList/addons/ai-assistant.json')), true, flags: JSON_THROW_ON_ERROR);
        $config['config_file'] = 'addons/ai-assistant.json';
        $module = Module::query()->create([
            'slug' => 'ai-assistant', 'name' => 'Помощник', 'version' => '1.0.0',
            'type' => 'addon', 'billing_model' => 'subscription',
            'limits' => ['max_ai_requests_per_month' => 7000],
        ]);
        $register = new ReflectionMethod(ModuleScanner::class, 'registerModule');

        $register->invoke(new ModuleScanner, $config);
        $module->refresh();
        $this->assertSame(7000, $module->pricing_config['legacy_ai_request_allowance']);
        $this->assertArrayNotHasKey('max_ai_requests_per_month', $module->toPublicArray()['limits']);
        $this->assertArrayNotHasKey('legacy_ai_request_allowance', $module->toPublicArray()['pricing_config']);

        $register->invoke(new ModuleScanner, $config);
        $module->refresh();
        $this->assertSame(7000, $module->pricing_config['legacy_ai_request_allowance']);
    }
}
