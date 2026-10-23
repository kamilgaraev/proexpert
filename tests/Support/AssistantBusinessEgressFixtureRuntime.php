<?php

declare(strict_types=1);

namespace Tests\Support;

use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEgress;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEgressRequest;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\LLM\OpenAIProvider;
use App\Models\User;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use LogicException;
use Psr\Http\Message\RequestInterface;

final class AssistantBusinessEgressFixtureRuntime
{
    public readonly OrdinaryAssistantEgress $egress;

    public readonly LLMProviderInterface $provider;

    private AssistantEgressFixtureAuthority $authority;

    public function __construct(private readonly int $organizationId, private readonly int $actorId, LLMProviderInterface $responses)
    {
        config(['ai-assistant.llm.provider' => 'timeweb', 'ai-assistant.llm.timeweb.base_uri' => 'https://fixture.invalid/v1']);
        $this->authority = new AssistantEgressFixtureAuthority;
        $this->egress = new OrdinaryAssistantEgress($this->authority);
        $mock = new MockHandler;
        $client = $this->egress->transport()->client(function (RequestInterface $wire, array $options) use ($mock): PromiseInterface {
            if (json_decode((string) $wire->getBody(), true, 512, JSON_THROW_ON_ERROR) !== $this->authority->approvedPayload) {
                throw new LogicException('business_fixture_wire_payload_mismatch');
            }
            $mock->append(new Response(200));

            return $mock($wire, $options);
        });
        $this->provider = new class($responses, $client) implements LLMProviderInterface
        {
            public function __construct(private readonly object $responses, private readonly ClientInterface $client) {}

            public function responses(array $input, array $options = []): array
            {
                $payload = OpenAIProvider::nativePayload($this->getModel(), $input, $options['tools'] ?? [],
                    $options['max_completion_tokens'], $options['tool_choice'] ?? null);
                $this->client->request('POST', 'https://fixture.invalid/v1/responses', ['headers' => ['Content-Type' => 'application/json'],
                    'body' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);

                return $this->responses->responses($input, $options);
            }

            public function chat(array $messages, array $options = []): array
            {
                throw new LogicException('business_fixture_chat_forbidden');
            }

            public function countTokens(string $text): int
            {
                return $this->responses->countTokens($text);
            }

            public function isAvailable(): bool
            {
                return $this->responses->isAvailable();
            }

            public function getModel(): string
            {
                return $this->responses->getModel();
            }
        };
        $this->egress->transport()->bind($this->provider);
    }

    public function approvePrepared(array $messages, array $options, int $organizationId, User $actor): void
    {
        if ($organizationId !== $this->organizationId || (int) $actor->getKey() !== $this->actorId) {
            throw new LogicException('business_fixture_scope_mismatch');
        }
        $this->authority->approve(OrdinaryAssistantEgressRequest::capture($messages, $options, $organizationId, $actor, $this->provider->getModel()));
    }
}
