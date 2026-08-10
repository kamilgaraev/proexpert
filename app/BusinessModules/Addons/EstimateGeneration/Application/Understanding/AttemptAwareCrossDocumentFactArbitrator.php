<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AttemptAwareNormativeLlmClient;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankOperationContext;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final readonly class AttemptAwareCrossDocumentFactArbitrator implements CrossDocumentFactArbitrator
{
    private const PROMPT_VERSION = 'cross-document-arbitration:v1';

    private const SCHEMA_VERSION = 'cross-document-arbitration-response:v1';

    public function __construct(
        private AttemptAwareNormativeLlmClient $client,
        private int $organizationId,
        private int $projectId,
        private int $sessionId,
        private string $checkpointClaimToken,
        private int $logicalAttempt,
    ) {
        new RerankOperationContext(
            $organizationId,
            $projectId,
            $sessionId,
            $checkpointClaimToken,
            'cross-document-link:v1',
            'cross-document-fact-link',
            $logicalAttempt,
        );
    }

    public function arbitrate(string $operationIdentity, array $payload, array $scope): array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $operationIdentity) !== 1
            || ($payload['operation_identity'] ?? null) !== $operationIdentity
            || ($scope['organization_id'] ?? null) !== $this->organizationId
            || ($scope['project_id'] ?? null) !== $this->projectId
            || ($scope['session_id'] ?? null) !== $this->sessionId
            || ($scope['source_version'] ?? null) !== ($payload['source_version'] ?? null)) {
            throw new InvalidArgumentException('Cross-document arbitration identity is invalid.');
        }
        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            $response = $this->client->chat([
                [
                    'role' => 'system',
                    'content' => 'Choose at most one candidate only when the supplied evidence locators support the same project fact. Return JSON with status, selected_fact_id and reason. Never confirm a fact.',
                ],
                [
                    'role' => 'user',
                    'content' => $encodedPayload,
                ],
            ], [
                'profile' => 'json',
                'temperature' => 0,
                'max_tokens' => 800,
                'timeout' => 30,
            ], [
                'organization_id' => $this->organizationId,
                'project_id' => $this->projectId,
                'session_id' => $this->sessionId,
                'work_item_key' => 'cross-document-fact-link',
                'checkpoint_claim_token' => $this->checkpointClaimToken,
                'input_version' => 'cross-document-link:v1',
                'logical_attempt' => $this->logicalAttempt,
                'candidate_set_hash' => $operationIdentity,
                'prompt_version' => self::PROMPT_VERSION,
                'schema_version' => self::SCHEMA_VERSION,
                'model_version' => 'estimate-generation-effective-settings',
                'dataset_versions' => [(string) ($payload['source_version'] ?? '')],
                'model_strategy' => AttemptAwareNormativeLlmClient::MODEL_STRATEGY_CONFIGURED_FALLBACKS,
            ]);
            $decoded = json_decode((string) ($response['content'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException|RuntimeException $exception) {
            throw new ExpectedArbitrationFailure('Cross-document arbitration provider is unavailable.', previous: $exception);
        }
        if (! is_array($decoded) || ! in_array($decoded['status'] ?? null, ['suggested', 'unresolved'], true)
            || ! is_string($decoded['reason'] ?? null) || trim($decoded['reason']) === ''
            || strlen($decoded['reason']) > 500
            || ($decoded['status'] === 'suggested' && ! is_string($decoded['selected_fact_id'] ?? null))) {
            return ['status' => 'unresolved', 'selected_fact_id' => null, 'reason' => 'invalid_arbitration_response'];
        }

        return [
            'status' => $decoded['status'],
            'selected_fact_id' => $decoded['status'] === 'suggested' ? $decoded['selected_fact_id'] : null,
            'reason' => $decoded['reason'],
        ];
    }
}
