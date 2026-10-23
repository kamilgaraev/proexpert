<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Egress;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Services\LLM\OpenAIProvider;
use App\Models\User;
use App\Services\Privacy\Gateway\Contracts\GatewayModelRequest;
use App\Support\AI\LunaModelPolicy;
use App\Support\AI\PreparedTokenBudget;
use JsonSerializable;
use LogicException;
use Throwable;

final readonly class OrdinaryAssistantEgressRequest implements JsonSerializable
{
    public const PURPOSE = 'assistant_chat';

    public const POLICY_VERSION = 'most-ai-v1-purpose-policy/0.2-product-approved-20261005';

    private function __construct(private array $scope, private array $payload, private string $digest) {}

    public static function capture(array $messages, array $options, int $organizationId, User $actor, string $providerModel, ?string $attemptRef = null): self
    {
        if ($organizationId < 1 || (int) $actor->getKey() < 1 || ! $actor->is_active
            || (int) $actor->current_organization_id !== $organizationId) {
            throw new AssistantEgressDenied('scope_mismatch');
        }

        try {
            $attemptRef ??= bin2hex(random_bytes(16));
            if (preg_match('/\A[0-9a-f]{32}\z/D', $attemptRef) !== 1) {
                throw new AssistantEgressDenied('source_unknown');
            }
            $provider = config('ai-assistant.llm.provider', 'timeweb');
            if (! is_string($provider) || ! in_array($provider, ['timeweb', 'openai'], true)) {
                throw new AssistantEgressDenied('unsupported_purpose');
            }
            $profile = $options['budget_profile'] ?? 'normal';
            if (! is_string($profile) || ! in_array($profile, ['short', 'normal', 'detailed'], true)) {
                throw new AssistantEgressDenied('unsupported_purpose');
            }
            $selectedModel = $options['model'] ?? $providerModel;
            if (! is_string($selectedModel)) {
                throw new AssistantEgressDenied('unsupported_purpose');
            }
            $model = LunaModelPolicy::assert($selectedModel, $provider);
            $baseUri = config('ai-assistant.llm.'.$provider.'.base_uri')
                ?: ($provider === 'timeweb' ? 'https://api.timeweb.ai/v1' : 'https://api.openai.com/v1');
            $uri = is_string($baseUri) ? parse_url($baseUri) : false;
            if (! is_array($uri) || ($uri['scheme'] ?? null) !== 'https' || empty($uri['host'])
                || isset($uri['user']) || isset($uri['pass']) || isset($uri['query']) || isset($uri['fragment'])) {
                throw new AssistantEgressDenied('unsupported_purpose');
            }
            $budget = $options['_prepared_token_budget'] ?? null;
            if (! $budget instanceof PreparedTokenBudget || $messages !== $budget->prepared['messages']) {
                throw new AssistantEgressDenied('source_stale');
            }
            $maxTokens = min((int) ($options['max_completion_tokens'] ?? 0), (int) $budget->prepared['max_completion_tokens']);
            if ($maxTokens < 1) {
                throw new AssistantEgressDenied('source_stale');
            }
            $payload = OpenAIProvider::nativePayload($model, $messages, (array) ($options['tools'] ?? []), $maxTokens, $options['tool_choice'] ?? null);
            $scope = [
                'purpose' => self::PURPOSE,
                'policy_version' => self::POLICY_VERSION,
                'attempt_ref' => $attemptRef,
                'organization_id' => $organizationId,
                'actor_id' => (int) $actor->getKey(),
                'provider' => $provider,
                'model' => $model,
                'profile' => $profile,
                'endpoint' => rtrim($baseUri, '/').'/responses',
            ];
            $digest = hash('sha256', GatewayModelRequest::canonicalJson(['scope' => $scope, 'payload' => $payload]));

            return new self($scope, $payload, $digest);
        } catch (AssistantEgressDenied $denied) {
            throw $denied;
        } catch (Throwable) {
            throw new AssistantEgressDenied('source_unknown');
        }
    }

    public function scope(): array
    {
        return $this->scope;
    }

    public function payload(): array
    {
        return $this->payload;
    }

    public function binding(): string
    {
        return $this->digest;
    }

    public function attemptRef(): string
    {
        return $this->scope['attempt_ref'];
    }

    public function jsonSerialize(): array
    {
        return ['schema_version' => 'ordinary-assistant-egress/1', 'payload_binding' => $this->digest];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    public function __serialize(): array
    {
        throw new LogicException('ordinary_assistant_egress_serialization_forbidden');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('ordinary_assistant_egress_deserialization_forbidden');
    }
}
