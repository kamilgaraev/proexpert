<?php

declare(strict_types=1);

namespace Tests\Unit\OneCExchange;

use App\Models\OneCBase;
use App\Models\OneCIntegrationProfile;
use App\Models\OneCProfileAuditEvent;
use App\Models\OneCProfileSecret;
use App\Models\Organization;
use App\Services\OneCExchange\OneCConnectionCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OneCConnectionCheckServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_connection_check_updates_profile_base_and_audit_without_secrets(): void
    {
        [$profile] = $this->profile();
        $secret = OneCProfileSecret::query()->create([
            'organization_id' => $profile->organization_id,
            'one_c_integration_profile_id' => $profile->id,
            'type' => 'bearer_token',
            'label' => 'Main token',
            'secret_value_encrypted' => 'plain-secret-token',
            'fingerprint' => hash('sha256', 'plain-secret-token'),
            'status' => 'active',
        ]);

        Http::fake([
            '*' => Http::response([
                'status' => 'ok',
                'protocol_version' => '1.0',
                'connector_version' => '2.4.1',
                'supported_scopes' => ['materials', 'contracts'],
                'read_only' => true,
            ]),
        ]);

        $result = $this->service()->test((int) $profile->organization_id, (int) $profile->id, null);

        self::assertSame('ok', $result['code']);
        self::assertSame('ok', $result['status']);
        self::assertSame('1.0', $result['protocol_version']);
        self::assertSame('2.4.1', $result['connector_version']);
        self::assertSame(['materials', 'contracts'], $result['supported_scopes']);
        self::assertSame('ok', $profile->refresh()->connection_status);
        self::assertSame('ok', $profile->base->refresh()->connection_status);
        self::assertSame('2.4.1', $profile->connector_version);

        Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://one-c.example/exchange/metadata'
            && $request->hasHeader('X-ProHelper-Connection-Check', 'read-only')
            && $request->hasHeader('Authorization', 'Bearer plain-secret-token'));

        $audit = OneCProfileAuditEvent::query()->latest('id')->first();
        self::assertSame('connection_check_run', $audit?->event_type);
        self::assertSame('ok', $audit?->result_code);

        $safeJson = json_encode([$result, $audit?->safe_context], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('plain-secret-token', $safeJson);
        self::assertStringNotContainsString('api_key=hidden', $safeJson);
        self::assertNotNull($secret->refresh()->fingerprint);
    }

    public function test_timeout_result_is_saved_as_safe_code(): void
    {
        [$profile] = $this->profile();
        $this->secret($profile);
        Http::fake(['*' => Http::response(['status' => 'timeout'], 408)]);

        $result = $this->service()->test((int) $profile->organization_id, (int) $profile->id, null);

        self::assertSame('timeout', $result['code']);
        self::assertSame('failed', $profile->refresh()->connection_status);
        self::assertSame('timeout', $profile->last_connection_check_code);
    }

    public function test_connection_check_selects_secret_matching_profile_auth_type_without_trimming_it(): void
    {
        [$profile] = $this->profile();
        $profile->forceFill(['auth_type' => 'basic'])->save();

        OneCProfileSecret::query()->create([
            'organization_id' => $profile->organization_id,
            'one_c_integration_profile_id' => $profile->id,
            'type' => 'basic',
            'label' => 'Main login',
            'username_encrypted' => ' user',
            'password_encrypted' => 'pass ',
            'fingerprint' => hash('sha256', 'basic-secret'),
            'status' => 'active',
        ]);
        OneCProfileSecret::query()->create([
            'organization_id' => $profile->organization_id,
            'one_c_integration_profile_id' => $profile->id,
            'type' => 'bearer_token',
            'label' => 'Another token',
            'secret_value_encrypted' => 'plain-secret-token',
            'fingerprint' => hash('sha256', 'plain-secret-token'),
            'status' => 'active',
        ]);

        Http::fake(['*' => Http::response([
            'protocol_version' => '1.0',
            'connector_version' => '2.4.1',
            'supported_scopes' => ['materials'],
        ])]);

        $result = $this->service()->test((int) $profile->organization_id, (int) $profile->id, null);

        self::assertSame('ok', $result['code']);
        Http::assertSent(static fn (Request $request): bool => $request->hasHeader(
            'Authorization',
            'Basic '.base64_encode(' user:pass ')
        ));
    }

    public function test_transport_error_result_is_saved_as_safe_code(): void
    {
        [$profile] = $this->profile();
        $this->secret($profile);
        Http::fake(['*' => Http::failedConnection()]);

        $result = $this->service()->test((int) $profile->organization_id, (int) $profile->id, null);

        self::assertSame('transport_error', $result['code']);
        self::assertSame('failed', $profile->refresh()->connection_status);
        self::assertSame('transport_error', $profile->last_connection_check_code);
    }

    public function test_unauthorized_result_does_not_expose_raw_response(): void
    {
        [$profile] = $this->profile();
        $this->secret($profile);
        Http::fake(['*' => Http::response(['token' => 'must-not-leak', 'trace' => 'hidden'], 401)]);

        $result = $this->service()->test((int) $profile->organization_id, (int) $profile->id, null);

        self::assertSame('unauthorized', $result['code']);
        self::assertSame('unauthorized', $profile->refresh()->connection_status);
        self::assertStringNotContainsString('must-not-leak', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_incompatible_protocol_version_returns_safe_failure(): void
    {
        [$profile] = $this->profile();
        $this->secret($profile);
        Http::fake(['*' => Http::response([
            'protocol_version' => '9.9',
            'connector_version' => '2.0',
            'supported_scopes' => ['materials'],
        ])]);

        $result = $this->service()->test((int) $profile->organization_id, (int) $profile->id, null);

        self::assertSame('incompatible_version', $result['code']);
        self::assertSame('failed', $profile->refresh()->connection_status);
        self::assertSame('9.9', $profile->protocol_version);
    }

    public function test_missing_scope_returns_safe_failure(): void
    {
        [$profile] = $this->profile(['materials', 'contracts']);
        $this->secret($profile);
        Http::fake(['*' => Http::response([
            'protocol_version' => '1.0',
            'connector_version' => '2.0',
            'supported_scopes' => ['materials'],
        ])]);

        $result = $this->service()->test((int) $profile->organization_id, (int) $profile->id, null);

        self::assertSame('missing_scope', $result['code']);
        self::assertSame('failed', $profile->refresh()->connection_status);
        self::assertSame(['missing_scope'], $profile->warning_codes);
        self::assertSame(['contracts'], $result['steps'][5]['safe_details']['missing_scopes']);
    }

    public function test_missing_secret_short_circuits_without_http_request(): void
    {
        [$profile] = $this->profile();
        Http::fake(['*' => Http::response(['status' => 'should-not-be-called'])]);

        $result = $this->service()->test((int) $profile->organization_id, (int) $profile->id, null);

        self::assertSame('secret_missing', $result['code']);
        self::assertSame('unconfigured', $profile->refresh()->connection_status);
        Http::assertNothingSent();
    }

    public function test_foreign_base_relation_is_not_used_or_exposed(): void
    {
        [$profile] = $this->profile();
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignBase = OneCBase::query()->create([
            'organization_id' => $foreignOrganization->id,
            'code' => 'foreign',
            'name' => 'Foreign 1C',
            'environment' => 'production',
            'connector' => 'http',
            'endpoint_url_encrypted' => 'https://foreign-one-c.example/exchange?api_key=hidden',
            'metadata_path' => '/metadata',
            'status' => 'active',
        ]);
        $profile->forceFill(['one_c_base_id' => $foreignBase->id])->save();
        $this->secret($profile);

        Http::fake(['*' => Http::response(['status' => 'should-not-be-called'])]);

        $result = $this->service()->test((int) $profile->organization_id, (int) $profile->id, null);

        self::assertSame('unconfigured', $result['code']);
        self::assertNull($result['profile']['base']);
        self::assertSame('untested', $foreignBase->refresh()->connection_status);
        Http::assertNothingSent();

        $audit = OneCProfileAuditEvent::query()->latest('id')->first();
        self::assertSame('connection_check_run', $audit?->event_type);
        self::assertNull($audit?->one_c_base_id);
    }

    /**
     * @param list<string> $allowedScopes
     * @return array{0: OneCIntegrationProfile, 1: OneCBase}
     */
    private function profile(array $allowedScopes = ['materials']): array
    {
        $organization = Organization::factory()->verified()->create();
        $base = OneCBase::query()->create([
            'organization_id' => $organization->id,
            'code' => 'main',
            'name' => 'Main 1C',
            'environment' => 'production',
            'connector' => 'http',
            'endpoint_url_encrypted' => 'https://one-c.example/exchange?api_key=hidden',
            'metadata_path' => '/metadata',
            'status' => 'active',
            'timeout_seconds' => 5,
            'connect_timeout_seconds' => 2,
        ]);
        $profile = OneCIntegrationProfile::query()->create([
            'organization_id' => $organization->id,
            'one_c_base_id' => $base->id,
            'code' => 'main-profile',
            'name' => 'Main profile',
            'environment' => 'production',
            'auth_type' => 'bearer_token',
            'exchange_mode' => 'manual',
            'status' => 'active',
            'allowed_scopes' => $allowedScopes,
        ]);

        return [$profile->load('base'), $base];
    }

    private function secret(OneCIntegrationProfile $profile): OneCProfileSecret
    {
        return OneCProfileSecret::query()->create([
            'organization_id' => $profile->organization_id,
            'one_c_integration_profile_id' => $profile->id,
            'type' => 'bearer_token',
            'label' => 'Main token',
            'secret_value_encrypted' => 'plain-secret-token',
            'fingerprint' => hash('sha256', 'plain-secret-token'),
            'status' => 'active',
        ]);
    }

    private function service(): OneCConnectionCheckService
    {
        return new OneCConnectionCheckService();
    }
}
