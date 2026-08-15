<?php

declare(strict_types=1);

namespace Tests\Unit\Seo;

use App\Jobs\Seo\SubmitUrlsToIndexNow;
use App\Services\Seo\IndexNowService;
use Illuminate\Config\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\TestCase;

class IndexNowServiceTest extends TestCase
{
    private Factory $http;

    private IndexNowService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new Factory;
        $this->service = new IndexNowService($this->http, new Repository([
            'blog' => [
                'indexnow' => [
                    'enabled' => true,
                    'endpoint' => 'https://yandex.com/indexnow',
                    'host' => 'xn--1-xtbgmf.xn--p1ai',
                    'key' => 'test-indexnow-key',
                    'key_location' => 'https://xn--1-xtbgmf.xn--p1ai/test-indexnow-key.txt',
                    'timeout_seconds' => 10,
                ],
            ],
        ]));
    }

    public function test_it_submits_unique_urls_using_the_indexnow_contract(): void
    {
        $this->http->fake([
            'https://yandex.com/indexnow' => $this->http->response(status: 202),
        ]);

        $this->service->submit([
            'https://xn--1-xtbgmf.xn--p1ai/blog/project-control',
            'https://xn--1-xtbgmf.xn--p1ai/blog/project-control',
        ]);

        $this->http->assertSentCount(1);
        $this->http->assertSent(static fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://yandex.com/indexnow'
            && $request->data() === [
                'host' => 'xn--1-xtbgmf.xn--p1ai',
                'key' => 'test-indexnow-key',
                'keyLocation' => 'https://xn--1-xtbgmf.xn--p1ai/test-indexnow-key.txt',
                'urlList' => ['https://xn--1-xtbgmf.xn--p1ai/blog/project-control'],
            ]);
    }

    public function test_it_does_not_send_when_indexnow_is_disabled(): void
    {
        $this->http->fake();
        $service = new IndexNowService($this->http, new Repository([
            'blog' => ['indexnow' => ['enabled' => false]],
        ]));

        $service->submit([
            'https://xn--1-xtbgmf.xn--p1ai/blog/project-control',
        ]);

        $this->http->assertNothingSent();
    }

    public function test_the_queue_job_executes_the_real_indexnow_client(): void
    {
        $this->http->fake([
            'https://yandex.com/indexnow' => $this->http->response(status: 200),
        ]);

        $job = new SubmitUrlsToIndexNow([
            'https://xn--1-xtbgmf.xn--p1ai/blog/project-control',
        ]);
        $job->handle($this->service);

        $this->http->assertSentCount(1);
    }
}
