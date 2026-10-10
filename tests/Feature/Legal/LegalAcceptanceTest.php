<?php

declare(strict_types=1);

namespace Tests\Feature\Legal;

use App\Http\Requests\Api\V1\Landing\Auth\RegisterRequest;
use App\Models\LegalAcceptanceEvent;
use App\Services\Legal\LegalAcceptanceService;
use App\Services\Legal\LegalDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\LegalAcceptanceFixture;
use Tests\TestCase;

final class LegalAcceptanceTest extends TestCase
{
    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('legal_acceptance_events');
        (require database_path('migrations/2026_10_06_190000_create_legal_acceptance_events_table.php'))->up();
    }

    public function test_empty_requisites_do_not_block_forms_or_documents(): void
    {
        $this->getJson('/api/public/legal')->assertOk()
            ->assertJsonPath('data.content_sha256', hash_file('sha256', resource_path('legal/2026-10-10.json')))
            ->assertJsonPath('data.privacy_ready', true)->assertJsonPath('data.commercial_ready', true)
            ->assertJsonPath('data.analytics_ready', true)->assertJsonPath('data.provider.name', '');
        $this->withHeaders(['Origin' => (string) config('web_auth.origins.lk.0'), 'Idempotency-Key' => 'empty-requisites-test'])
            ->postJson('/api/v1/landing/auth/register', [])->assertUnprocessable();
        $this->withHeaders(['Origin' => (string) config('web_auth.origins.public.0')])
            ->postJson('/api/public/contact', [])->assertUnprocessable();
        $this->withHeaders(['Origin' => (string) config('web_auth.origins.lk.0')])
            ->postJson('/api/v1/landing/billing/commercial/checkout', [])->assertUnauthorized();
        $this->assertDatabaseCount('legal_acceptance_events', 0);
    }

    public function test_acceptance_is_available_without_approval_flags_or_processor_registry(): void
    {
        $service = app(LegalDocumentService::class);
        config(['legal.reviewed' => false, 'legal.commercial_enabled' => false, 'legal.analytics_reviewed' => false, 'legal.subprocessors' => []]);
        $service->assertAccepted(LegalAcceptanceFixture::payload(['offer', 'processing', 'privacy']), ['offer', 'processing', 'privacy']);
        self::assertSame('', $service->snapshot('offer')['provider']['name']);
        self::assertTrue($service->manifest()['commercial_ready']);
    }

    public function test_provider_change_alters_hash_and_requisites_snapshot(): void
    {
        LegalAcceptanceFixture::enable();
        $service = app(LegalDocumentService::class);
        $before = $service->hash('offer');
        config(['legal.provider.name' => 'Другой тестовый поставщик']);
        self::assertNotSame($before, $service->hash('offer'));
        self::assertSame('Другой тестовый поставщик', $service->snapshot('offer')['provider']['name']);
    }

    public function test_stale_client_hash_is_rejected(): void
    {
        LegalAcceptanceFixture::enable();
        $input = LegalAcceptanceFixture::payload(['offer', 'processing', 'privacy']);
        $input['legal_documents']['processing'] = str_repeat('a', 64);
        $this->expectException(ValidationException::class);
        app(LegalDocumentService::class)->assertAccepted($input, ['offer', 'processing', 'privacy']);
    }

    public function test_register_requires_separate_processing_and_authority_choices(): void
    {
        LegalAcceptanceFixture::enable();
        $rules = (new RegisterRequest)->rules();
        $validator = Validator::make(['terms_accepted' => true, 'privacy_accepted' => true], $rules);
        self::assertTrue($validator->fails());
        self::assertArrayHasKey('processing_accepted', $validator->errors()->toArray());
        self::assertArrayHasKey('representative_authority', $validator->errors()->toArray());
        self::assertArrayHasKey('legal_documents.offer', $validator->errors()->toArray());
    }

    public function test_evidence_is_server_timestamped_idempotent_and_retains_text(): void
    {
        LegalAcceptanceFixture::enable();
        $service = app(LegalAcceptanceService::class);
        $request = Request::create('/api/public/contact', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.9', 'HTTP_USER_AGENT' => 'Legal test']);
        $first = $service->record('contactConsent', 'public_contact', '123', $request);
        $second = $service->record('contactConsent', 'public_contact', '123', $request);
        self::assertSame($first->id, $second->id);
        self::assertSame(app(LegalDocumentService::class)->hash('contactConsent'), $first->document_sha256);
        self::assertSame('Тестовый поставщик', $first->snapshot['provider']['name']);
        self::assertNotEmpty($first->snapshot['document']['sections']);
        self::assertNotSame('127.0.0.9', $first->evidence['ip_fingerprint']);
        self::assertNotEmpty($first->proof_hmac);
        $this->assertDatabaseCount('legal_acceptance_events', 1);
    }

    public function test_analytics_activation_and_revocation_preserve_distinct_events(): void
    {
        LegalAcceptanceFixture::enable();
        $visitor = (string) Str::uuid();
        $response = $this->postJson('/api/public/legal/analytics-consent', [
            ...LegalAcceptanceFixture::payload(['cookies']), 'analytics' => true,
            'visitor_id' => $visitor, 'event_id' => (string) Str::uuid(),
        ])->assertCreated();
        $receipt = $response->json('data.receipt_id');
        $this->postJson('/api/public/legal/analytics-status', ['visitor_id' => $visitor, 'receipt_id' => $receipt])->assertOk()->assertJsonPath('data.active', true);
        $this->postJson('/api/public/legal/analytics-consent', ['analytics' => false, 'visitor_id' => (string) Str::uuid(), 'event_id' => (string) Str::uuid(), 'receipt_id' => $receipt])->assertUnprocessable();
        $this->postJson('/api/public/legal/analytics-consent', ['analytics' => false, 'visitor_id' => $visitor, 'event_id' => (string) Str::uuid(), 'receipt_id' => $receipt])->assertCreated();
        $this->postJson('/api/public/legal/analytics-status', ['visitor_id' => $visitor, 'receipt_id' => $receipt])->assertOk()->assertJsonPath('data.active', false);
        $this->assertDatabaseHas('legal_acceptance_events', ['id' => $receipt, 'action' => 'consented']);
        self::assertSame(1, LegalAcceptanceEvent::query()->where('action', 'revoked')->count());
    }

    public function test_analytics_can_be_enabled_with_empty_requisites_after_explicit_consent(): void
    {
        $this->postJson('/api/public/legal/analytics-consent', [
            ...LegalAcceptanceFixture::payload(['cookies']), 'analytics' => true,
            'visitor_id' => (string) Str::uuid(), 'event_id' => (string) Str::uuid(),
        ])->assertCreated();
        $this->assertDatabaseCount('legal_acceptance_events', 1);
        self::assertSame('', LegalAcceptanceEvent::query()->firstOrFail()->snapshot['provider']['name']);
    }
}
