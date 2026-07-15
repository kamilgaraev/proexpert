<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Services\Billing\YooKassaWebhookSourceResolver;
use Illuminate\Http\Request;
use Tests\TestCase;

class YooKassaWebhookSourceResolverTest extends TestCase
{
    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.yookassa.webhook_source_cidrs', [
            '185.71.76.0/27',
            '2a02:5180::/32',
        ]);
        config()->set('services.yookassa.trusted_proxy_cidrs', []);
    }

    public function test_accepts_official_ipv4_and_ipv6_sources(): void
    {
        $resolver = app(YooKassaWebhookSourceResolver::class);

        $this->assertSame('185.71.76.31', $resolver->resolve($this->request('185.71.76.31')));
        $this->assertSame('2a02:5180::1234', $resolver->resolve($this->request('2a02:5180::1234')));
    }

    public function test_rejects_external_source_and_spoofed_forwarded_header(): void
    {
        $resolver = app(YooKassaWebhookSourceResolver::class);

        $this->assertNull($resolver->resolve($this->request('203.0.113.10')));
        $this->assertNull($resolver->resolve($this->request(
            '203.0.113.10',
            '185.71.76.8',
        )));
    }

    public function test_resolves_forwarded_chain_only_through_explicitly_trusted_proxies(): void
    {
        config()->set('services.yookassa.trusted_proxy_cidrs', [
            '10.0.0.0/8',
            '192.0.2.0/24',
        ]);

        $source = app(YooKassaWebhookSourceResolver::class)->resolve($this->request(
            '10.0.0.5',
            '185.71.76.7, 192.0.2.10',
        ));

        $this->assertSame('185.71.76.7', $source);
    }

    private function request(string $remoteAddress, ?string $forwardedFor = null): Request
    {
        $server = ['REMOTE_ADDR' => $remoteAddress];

        if ($forwardedFor !== null) {
            $server['HTTP_X_FORWARDED_FOR'] = $forwardedFor;
        }

        return Request::create('/api/v1/webhooks/yookassa', 'POST', server: $server);
    }
}
