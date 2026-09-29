<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\Interfaces\Billing\PaymentGatewayInterface;
use App\BusinessModules\Features\AIAssistant\Http\Controllers\AssistantCreditsController;
use App\BusinessModules\Features\AIAssistant\Http\Requests\AssistantCreditPurchaseRequest;
use App\Models\CommercialOrder;
use App\Models\CommercialPayment;
use App\Models\OrganizationPackageSubscription;
use App\Services\Billing\CommercialCheckoutService;
use App\Services\Credits\AICreditsNotReadyException;
use App\Services\Credits\AICreditService;
use Mockery;
use Illuminate\Support\Facades\Validator;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class ShadowCreditPurchaseGuardTest extends TestCase
{
    public function test_shadow_balance_distinguishes_billing_permission_from_purchase_availability(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        config(['ai-assistant-credits.enforce' => false]);
        $balance = app(AICreditService::class)->balance($fixture->organization, $fixture->owner);
        $this->assertSame('shadow', $balance['billing_mode']);
        $this->assertFalse($balance['charging_enabled']);
        $this->assertTrue($balance['can_manage_billing']);
        $this->assertFalse($balance['pack_purchase_enabled']);
        $this->assertFalse($balance['can_purchase']);
        $this->assertCount(3, $balance['packs']);
    }

    public function test_shadow_credit_checkout_does_not_create_order_or_call_payment_gateway(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        config(['ai-assistant-credits.enforce' => false]);
        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldNotReceive('createPayment');
        $this->app->instance(PaymentGatewayInterface::class, $gateway);
        $before = [CommercialOrder::query()->count(), CommercialPayment::query()->count(), OrganizationPackageSubscription::query()->count()];
        try {
            app(CommercialCheckoutService::class)->checkoutCredits($fixture->organization, $fixture->owner, 'ai-credits-1000', 'shadow-guard-credits');
            $this->fail('Shadow credit checkout must fail before payment intent creation.');
        } catch (AICreditsNotReadyException) {
            $this->assertSame($before, [CommercialOrder::query()->count(), CommercialPayment::query()->count(), OrganizationPackageSubscription::query()->count()]);
        }
    }

    public function test_paid_purchase_availability_uses_enforcement_and_billing_rights(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        config(['ai-assistant-credits.enforce' => true]);
        $credits = app(AICreditService::class);
        $owner = $credits->balance($fixture->organization, $fixture->owner);
        $this->assertSame('paid', $owner['billing_mode']);
        $this->assertTrue($owner['can_manage_billing']);
        $this->assertTrue($owner['pack_purchase_enabled']);
        $this->assertTrue($owner['can_purchase']);
        $member = $credits->balance($fixture->organization, $fixture->member);
        $this->assertTrue($member['pack_purchase_enabled']);
        $this->assertFalse($member['can_manage_billing']);
        $this->assertFalse($member['can_purchase']);
    }

    public function test_shadow_purchase_api_returns_unavailable_and_keeps_permission_denial_distinct(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        config(['ai-assistant-credits.enforce' => false]);
        $controller = app(AssistantCreditsController::class);
        foreach (['admin', 'mobile', 'lk'] as $surface) {
            $request = AssistantCreditPurchaseRequest::create('/api/v1/'.$surface.'/ai-assistant/credits/purchase', 'POST', ['pack_id' => 'ai-credits-1000']);
            $request->setContainer($this->app);
            $request->setUserResolver(fn () => $fixture->owner);
            $request->setValidator(Validator::make($request->all(), $request->rules()));
            $this->assertSame(503, $controller->purchase($request)->getStatusCode());
            $request->setUserResolver(fn () => $fixture->member);
            $this->assertSame(403, $controller->purchase($request)->getStatusCode());
        }
        $this->assertSame(0, CommercialOrder::query()->where('kind', 'ai_credits')->count());
    }

}
