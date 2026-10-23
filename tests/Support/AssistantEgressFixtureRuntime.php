<?php

declare(strict_types=1);

namespace Tests\Support;

use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEgress;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEgressRequest;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantProviderFactory;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\Models\User;
use App\Services\Logging\LoggingService;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use LogicException;
use Psr\Http\Message\RequestInterface;

final class AssistantEgressFixtureRuntime
{
    public readonly OrdinaryAssistantEgress $egress;

    public readonly LLMProviderInterface $provider;

    public int $wireCalls = 0;

    private AssistantEgressFixtureAuthority $authority;

    private array $preparedOptions = [];

    public function __construct(private readonly int $organizationId, private readonly int $actorId,
        private readonly object $responses, LoggingService $logging, ?OrdinaryAssistantEgress $egress = null)
    {
        config(['ai-assistant.llm.provider' => 'timeweb', 'ai-assistant.llm.timeweb.api_key' => 'offline-only-key',
            'ai-assistant.llm.timeweb.base_uri' => 'https://fixture.invalid/v1', 'ai-assistant.llm.timeweb.model' => 'openai/gpt-6-luna']);
        $this->authority = new AssistantEgressFixtureAuthority;
        $this->egress = $egress ?? new OrdinaryAssistantEgress($this->authority);
        $mock = new MockHandler;
        $this->provider = (new OrdinaryAssistantProviderFactory($this->egress, $logging))->make(
            function (RequestInterface $wire, array $httpOptions) use ($mock): PromiseInterface {
                $mock->append(function () use ($wire): Response {
                    $this->wireCalls++;
                    $payload = json_decode((string) $wire->getBody(), true, 512, JSON_THROW_ON_ERROR);
                    if ($payload !== $this->authority->approvedPayload) {
                        throw new LogicException('fixture_wire_payload_mismatch');
                    }
                    $result = $this->responses->responses($payload['input'], $this->preparedOptions);
                    $output = $result['output'] ?? [];
                    if (($result['content'] ?? '') !== '' && array_filter($output, static fn (array $item): bool => ($item['type'] ?? null) === 'message') === []) {
                        array_unshift($output, ['type' => 'message', 'id' => 'msg_fixture_'.$this->wireCalls, 'status' => 'completed',
                            'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $result['content'], 'annotations' => []]]]);
                    }
                    $input = $result['input_tokens'] ?? 0;
                    $generated = $result['output_tokens'] ?? 0;

                    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                        'id' => 'resp_fixture_'.$this->wireCalls, 'object' => 'response', 'created_at' => 1791540000,
                        'status' => $result['response_status'] ?? 'completed', 'model' => 'openai/gpt-6-luna',
                        'incomplete_details' => isset($result['incomplete_reason']) ? ['reason' => $result['incomplete_reason']] : null,
                        'output' => $output, 'max_output_tokens' => $payload['max_output_tokens'], 'parallel_tool_calls' => false,
                        'previous_response_id' => null, 'temperature' => null, 'top_p' => null, 'tool_choice' => 'auto', 'tools' => [],
                        'usage' => ($result['provider_usage_available'] ?? true) === false ? null : [
                            'input_tokens' => $input, 'output_tokens' => $generated, 'total_tokens' => $input + $generated,
                            'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]],
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                });

                return $mock($wire, $httpOptions);
            });
    }

    public function approvePrepared(array $messages, array $options, int $organizationId, User $actor): void
    {
        if ($organizationId !== $this->organizationId || (int) $actor->getKey() !== $this->actorId) {
            throw new LogicException('fixture_scope_mismatch');
        }
        $this->preparedOptions = $options;
        $this->authority->approve(OrdinaryAssistantEgressRequest::capture($messages, $options, $organizationId, $actor, $this->provider->getModel()));
    }
}
