<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantHttpRequestOptions;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AssistantHttpRequestOptionsTest extends TestCase
{
    public function test_transfer_callback_throttles_checkpoints_and_aborts_on_cancellation(): void
    {
        $checkpointCalls = 0;
        $cancellation = new RuntimeException('cancelled');
        $options = AssistantHttpRequestOptions::forCheckpoint(4.5, static function () use (&$checkpointCalls, $cancellation): void {
            $checkpointCalls++;
            if ($checkpointCalls === 2) {
                throw $cancellation;
            }
        });

        $capturedOptions = [];
        $client = new Client([
            ...$options,
            'handler' => static function ($request, array $requestOptions) use (&$capturedOptions) {
                $capturedOptions = $requestOptions;

                return Create::promiseFor(new Response(200));
            },
        ]);
        $client->sendRequest(new Request('POST', 'https://provider.invalid'));

        $this->assertSame(4.5, $capturedOptions['timeout']);
        $this->assertSame(4.5, $capturedOptions['connect_timeout']);
        $this->assertArrayHasKey('curl', $capturedOptions);
        $callback = $capturedOptions['curl'][CURLOPT_XFERINFOFUNCTION];

        $this->assertSame(0, $callback(null, 0, 0, 0, 0));
        $this->assertSame(0, $callback(null, 0, 0, 0, 0));
        $waitUntil = hrtime(true) + 1_000_000_000;
        while (hrtime(true) < $waitUntil) {
            usleep(10_000);
        }
        $this->assertSame(1, $callback(null, 0, 0, 0, 0));
        $this->assertSame(1, $callback(null, 0, 0, 0, 0));
        $this->assertSame(2, $checkpointCalls);

        try {
            ($capturedOptions['progress'])(0, 0, 0, 0);
            $this->fail('The Guzzle progress callback must propagate cancellation.');
        } catch (RuntimeException $exception) {
            $this->assertSame($cancellation, $exception);
            $this->assertSame(2, $checkpointCalls);
        }
    }
}
