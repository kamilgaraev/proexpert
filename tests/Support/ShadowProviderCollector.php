<?php

declare(strict_types=1);

namespace Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class ShadowProviderCollector
{
    public array $calls = [];
    public string $activeKind = 'assistant';
    public string $activeScenario = 'bootstrap';

    public function __construct(private readonly string $relay, private readonly string $token, private readonly array $pricing, private readonly array $priceEvidence = [])
    {
        if (! preg_match('#^http://127\.0\.0\.1:[0-9]+$#D', $relay) || $token === '') {
            throw new RuntimeException('Shadow relay must be authenticated loopback HTTP.');
        }
    }

    public function handler(): HandlerStack
    {
        $stack = HandlerStack::create();
        $stack->push(function (callable $next): callable {
            return function (RequestInterface $request, array $options) use ($next) {
                $host = $request->getUri()->getHost();
                if (! in_array($host, ['api.timeweb.ai', 'api.openai.com'], true)) {
                    throw new RuntimeException('Shadow external provider host is not trusted.');
                }
                $path = $request->getUri()->getPath();
                if (! in_array($path, ['/v1/responses', '/v1/chat/completions', '/v1/embeddings'], true) || $request->getMethod() !== 'POST') {
                    throw new RuntimeException('Shadow external provider endpoint is not supported.');
                }
                $payload = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
                $request->getBody()->rewind();
                if (in_array('unverified', array_column($this->calls, 'usage_source'), true)) {
                    throw new RuntimeException('Shadow external cost is unknown; another paid provider attempt is blocked.');
                }
                $spent = array_sum(array_column($this->calls, 'cost_micro_rub'));
                $inputUpperBound = max(32768, strlen((string) $request->getBody()));
                $outputUpperBound = $path === '/v1/embeddings' ? 0 : 4096;
                $callUpperBound = (int) ceil(($inputUpperBound * ($path === '/v1/embeddings' ? 45_000_000 : 13_500_000) + $outputUpperBound * 67_500_000) / 1_000_000);
                if ($spent + $callUpperBound > 200_000_000) {
                    throw new RuntimeException('Shadow diagnostic provider cost ceiling reached before external call.');
                }
                $request->getBody()->rewind();
                $request = $request->withUri(new \GuzzleHttp\Psr7\Uri($this->relay.$path))
                    ->withHeader('Authorization', 'Bearer '.$this->token);
                ShadowObservationVerifier::progress($this->activeScenario, 'provider_dispatched', ['endpoint' => $path]);
                return $next($request, $options)->then(function (ResponseInterface $response) use ($host, $path, $payload) {
                    $raw = (string) $response->getBody();
                    $response->getBody()->rewind();
                    $body = json_decode($raw, true);
                    $usage = is_array($body) && is_array($body['usage'] ?? null) ? $body['usage'] : null;
                    $input = $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? null;
                    $output = $usage['output_tokens'] ?? $usage['completion_tokens'] ?? ($path === '/v1/embeddings' ? 0 : null);
                    $verified = is_int($input) && $input >= 0 && is_int($output) && $output >= 0;
                    $inputRate = $path === '/v1/embeddings' ? 45_000_000 : 13_500_000;
                    $outputRate = $path === '/v1/embeddings' ? 0 : 67_500_000;
                    $priceMatched = false;
                    foreach ((array) ($this->priceEvidence['prices'] ?? []) as $sourcePrice) {
                        $family = $path === '/v1/embeddings' ? 'text-embedding-3-large' : 'gpt-6-luna';
                        if (str_contains((string) ($sourcePrice['model'] ?? ''), $family)
                            && (int) round((float) ($sourcePrice['input_rub_per_million'] ?? -1) * 1_000_000) === $inputRate
                            && (int) round((float) ($sourcePrice['output_rub_per_million'] ?? -1) * 1_000_000) === $outputRate) {
                            $priceMatched = true;
                        }
                    }
                    $priceVerified = $priceMatched && ($this->priceEvidence['html_verified'] ?? false) === true && ($this->priceEvidence['source'] ?? '') === 'https://timeweb.cloud/services/ai-gateway' && preg_match('/^[a-f0-9]{64}$/D', $this->priceEvidence['html_sha256'] ?? '') === 1;
                    $cost = $verified ? (int) ceil(($input * $inputRate + $output * $outputRate) / 1_000_000) : 0;
                    $this->calls[] = [
                        'kind' => $path === '/v1/embeddings' ? 'index' : $this->activeKind,
                        'success' => $response->getStatusCode() >= 200 && $response->getStatusCode() < 300 && ! in_array($body['status'] ?? null, ['incomplete', 'failed', 'cancelled'], true) && ! in_array($body['choices'][0]['finish_reason'] ?? null, ['length', 'content_filter'], true),
                        'generative' => $path !== '/v1/embeddings', 'provider' => $host === 'api.timeweb.ai' ? 'timeweb' : 'openai',
                        'model' => $body['model'] ?? $payload['model'] ?? '', 'raw_model' => $body['model'] ?? $payload['model'] ?? '',
                        'input_tokens' => $input ?? 0, 'output_tokens' => $output ?? 0, 'cost_micro_rub' => $cost,
                        'usage_source' => $verified ? 'provider_response' : 'unverified',
                        'evidence' => $verified && $priceVerified ? 'provider_usage' : 'unverified_usage_or_pricing',
                        'provider_evidence_sha256' => hash('sha256', $raw), 'provider_usage' => $usage,
                        'http_status' => $response->getStatusCode(), 'raw_provider_response' => $raw, 'cost_verification' => $verified && $priceVerified ? 'primary_pricing_snapshot' : 'unverified_usage_or_pricing', 'pricing_evidence' => $this->priceEvidence, 'pricing_snapshot' => ['input_micro_rub_per_million' => $inputRate, 'output_micro_rub_per_million' => $outputRate], 'observed_at' => gmdate('c'),
                    ];
                    return $response;
                }, function ($failure) use ($host, $path, $payload) {
                    $error = ['exception_class' => is_object($failure) ? $failure::class : gettype($failure)];
                    $this->calls[] = ['kind' => $path === '/v1/embeddings' ? 'index' : $this->activeKind, 'success' => false,
                        'generative' => $path !== '/v1/embeddings', 'provider' => $host === 'api.timeweb.ai' ? 'timeweb' : 'openai',
                        'model' => $payload['model'] ?? 'unknown', 'raw_model' => $payload['model'] ?? 'unknown',
                        'input_tokens' => 0, 'output_tokens' => 0, 'cost_micro_rub' => 0, 'usage_source' => 'unverified',
                        'evidence' => 'transport_failed_without_provider_usage', 'provider_evidence_sha256' => hash('sha256', json_encode($error, JSON_THROW_ON_ERROR)),
                        'transport_evidence' => $error];
                    return new \GuzzleHttp\Promise\RejectedPromise($failure);
                });
            };
        });
        return $stack;
    }

    public function client(): Client
    {
        return new Client(['handler' => $this->handler(), 'timeout' => 90, 'connect_timeout' => 5]);
    }
}
