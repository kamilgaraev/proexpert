<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\Services\Monitoring\GlitchTipOrchestratorService;
use PHPUnit\Framework\TestCase;

class GlitchTipOrchestratorServiceTest extends TestCase
{
    public function test_normalizes_glitchtip_webhook_payload(): void
    {
        $service = new GlitchTipOrchestratorService();

        $payload = [
            'action' => 'issue.created',
            'issue_id' => 42,
            'project' => 'prohelper-backend',
            'data' => [
                'event_id' => 'abc123',
                'title' => 'Division by zero',
                'message' => 'Division by zero in billing pipeline',
                'level' => 'error',
                'request' => [
                    'url' => 'https://example.com/api/v1/admin/billing/invoices',
                    'method' => 'POST',
                ],
                'tags' => [
                    ['key' => 'organization_id', 'value' => '77'],
                    ['key' => 'user_id', 'value' => '15'],
                    ['key' => 'module', 'value' => 'billing'],
                    ['key' => 'interface', 'value' => 'admin'],
                    ['key' => 'route_name', 'value' => 'admin.billing.store'],
                    ['key' => 'correlation_id', 'value' => 'req_123'],
                    ['key' => 'release', 'value' => 'prohelper@abc'],
                    ['key' => 'environment', 'value' => 'production'],
                ],
                'exception' => [
                    'values' => [
                        [
                            'stacktrace' => [
                                'frames' => [
                                    [
                                        'filename' => '/var/www/app/Services/BillingService.php',
                                        'function' => 'store',
                                        'lineno' => 91,
                                        'module' => 'App\\Services\\BillingService',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $normalized = $service->normalizeWebhookPayload($payload);

        self::assertSame(42, $normalized['issue_id']);
        self::assertSame('abc123', $normalized['event_id']);
        self::assertSame('77', $normalized['organization_id']);
        self::assertSame('15', $normalized['user_id']);
        self::assertSame('billing', $normalized['module']);
        self::assertSame('admin', $normalized['interface']);
        self::assertSame('prohelper@abc', $normalized['release']);
        self::assertSame('/api/v1/admin/billing/invoices', $normalized['path']);
        self::assertCount(1, $normalized['top_frames']);
    }

    public function test_matches_secret(): void
    {
        $service = new GlitchTipOrchestratorService();

        self::assertTrue($service->matchesSecret('top-secret', 'top-secret'));
        self::assertFalse($service->matchesSecret('top-secret', 'wrong-secret'));
        self::assertTrue($service->matchesSecret('', null, true));
        self::assertFalse($service->matchesSecret('', null, false));
    }

    public function test_summarizes_issues_response(): void
    {
        $service = new GlitchTipOrchestratorService();

        $issues = $service->summarizeIssues([
            [
                'id' => '1',
                'shortId' => 'PROHELPER-1',
                'title' => 'Database timeout',
                'status' => 'unresolved',
                'count' => '5',
                'lastSeen' => '2026-03-11T12:00:00Z',
            ],
        ]);

        self::assertCount(1, $issues);
        self::assertSame('PROHELPER-1', $issues[0]['short_id']);
        self::assertSame('Database timeout', $issues[0]['title']);
        self::assertSame('unresolved', $issues[0]['status']);
    }

    public function test_normalizes_slack_compatible_webhook_payload(): void
    {
        $service = new GlitchTipOrchestratorService();

        $payload = [
            'text' => '[prohelper-backend] New issue detected #77',
            'attachments' => [
                [
                    'title' => 'Database timeout',
                    'text' => 'The request timed out. https://glitchtip.example/issues/77',
                    'fields' => [
                        ['title' => 'Project', 'value' => 'prohelper-backend'],
                        ['title' => 'Environment', 'value' => 'production'],
                        ['title' => 'Release', 'value' => 'prohelper@abc1234'],
                        ['title' => 'Issue ID', 'value' => '77'],
                        ['title' => 'Module', 'value' => 'billing'],
                    ],
                ],
            ],
        ];

        $normalized = $service->normalizeWebhookPayload($payload);

        self::assertSame('77', $normalized['issue_id']);
        self::assertSame('prohelper-backend', $normalized['project']);
        self::assertSame('production', $normalized['environment']);
        self::assertSame('prohelper@abc1234', $normalized['release']);
        self::assertSame('billing', $normalized['module']);
        self::assertSame('Database timeout', $normalized['title']);
        self::assertSame('https://glitchtip.example/issues/77', $normalized['url']);
    }
}
