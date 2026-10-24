<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Egress;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Services\AssistantHttpRequestOptions;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestPhaseTimer;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Utils;
use Psr\Http\Message\RequestInterface;
use Throwable;

final class OrdinaryAssistantTransport
{
    private ?LLMProviderInterface $provider = null;

    private ?OrdinaryAssistantEgressRequest $request = null;

    private ?Closure $checkpoint = null;

    private bool $consumed = false;

    private bool $clientCreated = false;

    private ?float $timeout = null;

    private ?string $requestId = null;

    public function client(?callable $handler = null): ClientInterface
    {
        if ($this->clientCreated) {
            throw new AssistantEgressDenied('scope_mismatch');
        }
        $this->clientCreated = true;
        $handler ??= Utils::chooseHandler();
        $guarded = function (RequestInterface $wire, array $options) use ($handler): PromiseInterface {
            if ($this->request === null || $this->checkpoint === null || $this->timeout === null || $this->consumed) {
                throw new AssistantEgressDenied('access_denied');
            }
            if ($this->requestId === null) {
                ($this->checkpoint)();
            } else {
                AssistantRequestPhaseTimer::run($this->requestId, 'native_prewire_guard', $this->checkpoint);
            }
            $expected = json_encode($this->request->payload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if ($wire->getMethod() !== 'POST' || (string) $wire->getUri() !== $this->request->scope()['endpoint']
                || $wire->getHeaderLine('Content-Type') !== 'application/json'
                || (string) $wire->getBody() !== $expected) {
                throw new AssistantEgressDenied('scope_mismatch');
            }
            $this->consumed = true;
            $options['timeout'] = $this->timeout;
            $options['connect_timeout'] = min(5.0, $this->timeout);
            $progressCount = 0;
            $progressMilliseconds = 0.0;
            $progressKnown = true;
            $observer = $this->requestId === null ? null : static function (float $duration) use (&$progressCount, &$progressMilliseconds, &$progressKnown): void {
                if (! is_finite($duration) || $duration < 0 || $progressCount >= 65536 || $progressMilliseconds + $duration > 3_600_000) {
                    $progressKnown = false;
                    return;
                }
                $progressCount++;
                $progressMilliseconds += $duration;
            };
            if (app()->bound(AssistantRequestExecutionContext::class)) {
                $configure = fn (): array => array_replace($options, AssistantHttpRequestOptions::forContext(
                    $this->timeout, app(AssistantRequestExecutionContext::class), $observer));
                $options = $this->requestId === null ? $configure()
                    : AssistantRequestPhaseTimer::run($this->requestId, 'native_transport_options', $configure);
            }

            if ($this->requestId === null) {
                return $handler($wire, $options);
            }
            $timer = AssistantRequestPhaseTimer::start($this->requestId, 'native_http_handler');
            $finish = static function (?Throwable $failure, bool $success) use ($timer, &$progressCount, &$progressMilliseconds, &$progressKnown): void {
                $timer->finish($failure, successful: $success, progressGuardCount: $progressKnown ? $progressCount : null,
                    progressGuardMilliseconds: $progressKnown ? $progressMilliseconds : null);
            };
            try {
                return $handler($wire, $options)->then(
                    static function (mixed $response) use ($finish): mixed { $finish(null, true); return $response; },
                    static function (mixed $reason) use ($finish): PromiseInterface {
                        $finish($reason instanceof Throwable ? $reason : null, false);
                        return Create::rejectionFor($reason);
                    },
                );
            } catch (Throwable $exception) {
                $finish($exception, false);
                throw $exception;
            }
        };

        return new Client(['handler' => HandlerStack::create($guarded), 'allow_redirects' => false]);
    }

    public function bind(LLMProviderInterface $provider): void
    {
        if (! $this->clientCreated || $this->provider !== null) {
            throw new AssistantEgressDenied('scope_mismatch');
        }
        $this->provider = $provider;
    }

    public function isBound(LLMProviderInterface $provider): bool
    {
        return $provider === $this->provider;
    }

    public function arm(LLMProviderInterface $provider, OrdinaryAssistantEgressRequest $request, Closure $checkpoint, float $timeout, ?string $requestId = null): void
    {
        if ($provider !== $this->provider || $this->request !== null) {
            throw new AssistantEgressDenied('scope_mismatch');
        }
        if (! is_finite($timeout) || $timeout <= 0) {
            throw new AssistantEgressDenied('unsupported_purpose');
        }
        $this->request = $request;
        $this->checkpoint = $checkpoint;
        $this->consumed = false;
        $this->timeout = $timeout;
        $this->requestId = $requestId !== null && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $requestId) === 1 ? $requestId : null;
    }

    public function clear(): void
    {
        $this->request = null;
        $this->checkpoint = null;
        $this->consumed = false;
        $this->timeout = null;
        $this->requestId = null;
    }

    public function dispatched(): bool
    {
        return $this->consumed;
    }

    public function __debugInfo(): array
    {
        return ['armed' => $this->request !== null, 'consumed' => $this->consumed];
    }
}
