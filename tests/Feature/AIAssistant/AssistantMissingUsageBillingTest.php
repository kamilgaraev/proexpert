<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantBudgetExceeded;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\Models\Credits\AICreditLedgerEntry;
use App\Models\Credits\AICreditProviderUsage;
use App\Models\Credits\AICreditReservation;
use App\Services\Credits\AICreditService;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssistantCreditReadinessFixture;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantMissingUsageBillingTest extends TestCase
{
    private ?string $approvalPath = null;

    #[DataProvider('availabilityCases')]
    public function test_actual_lifecycle_keeps_unknown_success_free_and_known_zero_billable(bool $available, int $charge): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        self::assertTrue(app(AIPermissionChecker::class)->canUseAssistant($fixture->owner, $fixture->organization->id));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('u', 32)), 'ai-assistant-credits.enforce' => true]);
        $this->approvalPath = tempnam(sys_get_temp_dir(), 'assistant-missing-usage-');
        config(['ai-assistant-credits.readiness_approval_path' => $this->approvalPath]);
        AssistantCreditReadinessFixture::write($this->approvalPath, (array) config('ai-assistant-credits'), (string) config('app.key'));
        $credits = app(AICreditService::class);
        $credits->grant($fixture->organization, 10000, 'purchase', null, 'isolated-missing-usage-fixture');
        $payload = ['request_id' => (string) Str::uuid(), 'message' => 'Покажи текущие данные', 'profile' => 'short', 'allow_actions' => false,
            'conversation_id' => null, 'context' => []];
        $quote = $credits->quote($fixture->organization, $fixture->owner, $payload);
        $payload['quote_id'] = $quote['quote_id'];
        $lifecycle = app(AssistantRequestLifecycle::class);
        $request = $lifecycle->start($fixture->organization, $fixture->owner, null, $payload)['request'];
        $attempt = $lifecycle->beforeProviderCall($request, $fixture->owner, 30, 64);
        $receipt = ['content' => 'Текст результата', 'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna', 'tokens_used' => 0,
            'input_tokens' => $available ? 0 : null, 'output_tokens' => $available ? 0 : null,
            'provider_usage_available' => $available, 'usage_source' => $available ? 'provider_response' : 'unavailable',
            'token_calibration' => ['actual_input_tokens' => $available ? 0 : null, 'provider_usage_available' => $available,
                'persisted' => $available, 'profile_input_exceeded' => false]];
        $lifecycle->recordProviderUsage($request, $receipt, $attempt);
        $lifecycle->recordProviderUsage($request, $receipt, $attempt);
        if (! $available) {
            self::assertSame('token_calibration_unavailable', $request->fresh()->error_code);
            try { $lifecycle->beforeProviderCall($request, $fixture->owner, 30, 64); self::fail('Unknown calibration must block another provider attempt.'); }
            catch (AssistantBudgetExceeded) {}
        }
        $response = $lifecycle->complete($request, $fixture->owner, ['message' => ['content' => $receipt['content'], 'metadata' => ['source_refs' => []]]]);
        $reservation = AICreditReservation::query()->findOrFail($request->reservation_id);
        $usage = AICreditProviderUsage::query()->where('ai_credit_reservation_id', $reservation->id)->sole();
        self::assertSame($available, $usage->metadata['provider_usage_available']);
        self::assertSame($available, $usage->metadata['cost_available']);
        self::assertFalse($usage->metadata['cost_is_estimate']);
        self::assertSame($receipt['usage_source'], $usage->metadata['usage_source']);
        self::assertSame(0, $usage->cost_micro_rub);
        self::assertTrue($usage->is_successful);
        self::assertSame($charge, $response['credit_usage']['charged_minor']);
        self::assertSame($charge, $response['credit_usage']['projected_charge_minor']);
        self::assertSame($charge, $reservation->consumed_minor);
        self::assertSame('finalized', $reservation->status);
        self::assertSame(10000 - $charge, $credits->balance($fixture->organization)['available_minor']);
        self::assertSame(0, $credits->balance($fixture->organization)['reserved_minor']);
        self::assertSame(-$charge, AICreditLedgerEntry::query()->where('organization_id', $fixture->organization->id)->where('type', 'consume')->sole()->amount_minor);
        self::assertEquals($response, $lifecycle->start($fixture->organization, $fixture->owner, null, $payload)['response']);
        self::assertSame(1, AICreditProviderUsage::query()->where('ai_credit_reservation_id', $reservation->id)->count());
    }

    public static function availabilityCases(): array { return [[false, 0], [true, 50]]; }

    public function test_cancel_preserves_known_prior_cost_and_unknown_failed_attempt_without_charging(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('u', 32)), 'ai-assistant-credits.enforce' => true]);
        $this->approvalPath = tempnam(sys_get_temp_dir(), 'assistant-missing-usage-');
        config(['ai-assistant-credits.readiness_approval_path' => $this->approvalPath]);
        AssistantCreditReadinessFixture::write($this->approvalPath, (array) config('ai-assistant-credits'), (string) config('app.key'));
        $credits = app(AICreditService::class);
        $credits->grant($fixture->organization, 10000, 'purchase', null, 'isolated-cancel-usage-fixture');
        $payload = ['request_id' => (string) Str::uuid(), 'message' => 'Покажи данные', 'profile' => 'normal', 'allow_actions' => false, 'conversation_id' => null, 'context' => []];
        $payload['quote_id'] = $credits->quote($fixture->organization, $fixture->owner, $payload)['quote_id'];
        $lifecycle = app(AssistantRequestLifecycle::class);
        $request = $lifecycle->start($fixture->organization, $fixture->owner, null, $payload)['request'];
        $first = $lifecycle->beforeProviderCall($request, $fixture->owner, 30, 64);
        $lifecycle->recordProviderUsage($request, ['provider' => 'timeweb', 'model' => 'openai/gpt-6-luna', 'input_tokens' => 30, 'output_tokens' => 10,
            'provider_usage_available' => true, 'token_calibration' => ['persisted' => true, 'profile_input_exceeded' => false]], $first);
        $known = AICreditProviderUsage::query()->where('ai_credit_reservation_id', $request->reservation_id)->sole();
        self::assertGreaterThan(0, $known->cost_micro_rub);
        $second = $lifecycle->beforeProviderCall($request, $fixture->owner, 30, 64);
        $lifecycle->recordProviderUsage($request, ['provider' => 'timeweb', 'model' => 'openai/gpt-6-luna', 'input_tokens' => null, 'output_tokens' => null,
            'provider_usage_available' => false, 'token_calibration' => ['persisted' => false, 'profile_input_exceeded' => false]], $second, false);
        $lifecycle->cancel($request->request_id, $fixture->owner, $fixture->organization->id);
        $lifecycle->fail($request, 'provider_usage_unavailable');
        self::assertSame('cancelled', $request->fresh()->status);
        $rows = AICreditProviderUsage::query()->where('ai_credit_reservation_id', $request->reservation_id)->orderBy('id')->get();
        self::assertCount(2, $rows);
        self::assertSame($known->cost_micro_rub, $rows[0]->cost_micro_rub);
        self::assertTrue($rows[0]->is_successful);
        self::assertTrue($rows[0]->metadata['provider_usage_available']);
        self::assertSame(0, $rows[1]->cost_micro_rub);
        self::assertFalse($rows[1]->is_successful);
        self::assertFalse($rows[1]->metadata['provider_usage_available']);
        self::assertSame(0, AICreditReservation::query()->findOrFail($request->reservation_id)->consumed_minor);
        self::assertSame(0, $credits->balance($fixture->organization)['reserved_minor']);
        self::assertSame(10000, $credits->balance($fixture->organization)['available_minor']);
    }

    protected function tearDown(): void
    {
        if ($this->approvalPath !== null && is_file($this->approvalPath)) { unlink($this->approvalPath); }
        parent::tearDown();
    }
}
