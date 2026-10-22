<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Egress;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Services\AssistantHttpRequestOptions;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Utils;
use Psr\Http\Message\RequestInterface;

final class OrdinaryAssistantTransport
{
    private ?LLMProviderInterface $provider = null;

    private ?OrdinaryAssistantEgressRequest $request = null;

    private ?Closure $checkpoint = null;

    private bool $consumed = false;

    private bool $clientCreated = false;

    private ?float $timeout = null;

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
            ($this->checkpoint)();
            $expected = json_encode($this->request->payload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if ($wire->getMethod() !== 'POST' || (string) $wire->getUri() !== $this->request->scope()['endpoint']
                || $wire->getHeaderLine('Content-Type') !== 'application/json'
                || (string) $wire->getBody() !== $expected) {
                throw new AssistantEgressDenied('scope_mismatch');
            }
            $this->consumed = true;
            $options['timeout'] = $this->timeout;
            $options['connect_timeout'] = min(5.0, $this->timeout);
            if (app()->bound(AssistantRequestExecutionContext::class)) {
                $options = array_replace($options, AssistantHttpRequestOptions::forContext(
                    $this->timeout, app(AssistantRequestExecutionContext::class)));
            }

            return $handler($wire, $options);
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

    public function arm(LLMProviderInterface $provider, OrdinaryAssistantEgressRequest $request, Closure $checkpoint, float $timeout): void
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
    }

    public function clear(): void
    {
        $this->request = null;
        $this->checkpoint = null;
        $this->consumed = false;
        $this->timeout = null;
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
