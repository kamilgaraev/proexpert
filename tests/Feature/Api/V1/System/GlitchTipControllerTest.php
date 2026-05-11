<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\System;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GlitchTipControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        Config::set('glitchtip.latest_incident_cache_key', 'tests.glitchtip.latest_incident');
        Config::set('glitchtip.auto_create_github_issue', false);
        Config::set('glitchtip.github.token', '');
        Config::set('glitchtip.github.repository', '');
    }

    public function test_webhook_rejects_missing_secret(): void
    {
        Config::set('glitchtip.webhook_secret', 'webhook-secret');
        Config::set('glitchtip.allow_unsigned_webhooks', false);

        $response = $this->postJson('/api/v1/system/glitchtip/webhook', [
            'title' => 'Production error',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
        $this->assertNull(Cache::get('tests.glitchtip.latest_incident'));
    }

    public function test_webhook_accepts_bearer_secret_and_stores_normalized_incident(): void
    {
        Config::set('glitchtip.webhook_secret', 'webhook-secret');
        Config::set('glitchtip.allow_unsigned_webhooks', false);
        Config::set('glitchtip.project', 'prohelper-api');

        $response = $this
            ->withHeader('Authorization', 'Bearer webhook-secret')
            ->postJson('/api/v1/system/glitchtip/webhook', [
                'issue_id' => '42',
                'event_id' => 'evt-1',
                'data' => [
                    'title' => 'SQL timeout',
                    'level' => 'error',
                    'environment' => 'prod',
                    'request' => [
                        'url' => 'https://example.test/api/v1/admin/projects',
                        'method' => 'GET',
                    ],
                    'tags' => [
                        ['key' => 'organization_id', 'value' => '17'],
                        ['key' => 'interface', 'value' => 'admin'],
                    ],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.incident.issue_id', '42');
        $response->assertJsonPath('data.incident.title', 'SQL timeout');
        $response->assertJsonPath('data.incident.path', '/api/v1/admin/projects');
        $response->assertJsonPath('data.incident.organization_id', '17');
        $response->assertJsonPath('data.github_issue', null);

        $incident = Cache::get('tests.glitchtip.latest_incident');

        $this->assertIsArray($incident);
        $this->assertSame('evt-1', $incident['event_id']);
        $this->assertSame('admin', $incident['interface']);
    }

    public function test_issues_requires_internal_token_and_clamps_limit_for_glitchtip_request(): void
    {
        Config::set('glitchtip.internal_token', 'internal-secret');
        Config::set('glitchtip.url', 'https://glitchtip.example');
        Config::set('glitchtip.token', 'api-token');
        Config::set('glitchtip.organization', 'prohelper');
        Config::set('glitchtip.project', 'backend');
        Config::set('glitchtip.period', '24h');

        Http::fake([
            'glitchtip.example/*' => Http::response([
                [
                    'id' => '100',
                    'shortId' => 'BACKEND-1',
                    'title' => 'Unhandled exception',
                    'culprit' => 'ProjectController@index',
                    'level' => 'error',
                    'status' => 'unresolved',
                    'count' => 5,
                    'userCount' => 3,
                    'permalink' => 'https://glitchtip.example/issues/100',
                    'lastSeen' => '2026-05-11T08:00:00Z',
                    'firstSeen' => '2026-05-10T08:00:00Z',
                ],
            ]),
        ]);

        $unauthorized = $this->getJson('/api/v1/system/glitchtip/issues');

        $unauthorized->assertStatus(401);
        $unauthorized->assertJsonPath('success', false);

        $response = $this
            ->withHeader('X-Internal-Token', 'internal-secret')
            ->getJson('/api/v1/system/glitchtip/issues?limit=250');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.0.id', '100');
        $response->assertJsonPath('data.0.short_id', 'BACKEND-1');
        $response->assertJsonPath('data.0.user_count', 3);

        Http::assertSent(static fn (Request $request): bool =>
            $request->hasHeader('Authorization', 'Bearer api-token')
            && str_contains($request->url(), '/api/0/projects/prohelper/backend/issues/')
            && str_contains($request->url(), 'limit=100')
        );
    }

    public function test_pull_request_requires_head_branch_after_internal_token(): void
    {
        Config::set('glitchtip.internal_token', 'internal-secret');

        $response = $this
            ->withHeader('X-GlitchTip-Internal-Token', 'internal-secret')
            ->postJson('/api/v1/system/glitchtip/pull-request', [
                'head' => '   ',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }
}
