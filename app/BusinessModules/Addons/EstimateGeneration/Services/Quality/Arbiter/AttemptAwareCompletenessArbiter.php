<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiAttemptAuthorizer;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPhysicalAttemptIdentity;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireException;
use InvalidArgumentException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final readonly class AttemptAwareCompletenessArbiter implements CompletenessArbiter
{
    public function __construct(
        private RerankWireClient $wire,
        private AiUsageStore $usageStore,
        private AiAttemptAuthorizer $budgetAuthorizer,
        private string $configuredModel,
        private string $configuredPromptVersion,
        private string $schemaVersion,
        private int $maxInputTokens,
        private int $maxOutputTokens,
        private int $timeoutSeconds,
    ) {
        if (preg_match('#^[A-Za-z0-9._/-]{1,160}$#', $configuredModel) !== 1
            || preg_match('~^[A-Za-z0-9:._/-]{1,160}$~', $configuredPromptVersion) !== 1
            || preg_match('~^[A-Za-z0-9:._/-]{1,160}$~', $schemaVersion) !== 1
            || $maxInputTokens < 1 || $maxOutputTokens < 1 || $timeoutSeconds < 1) {
            throw new InvalidArgumentException('Invalid completeness arbiter configuration.');
        }
    }

    public function model(): string
    {
        return $this->configuredModel;
    }

    public function promptVersion(): string
    {
        return $this->configuredPromptVersion;
    }

    /** @param array<string, mixed> $context
     *  @return array<string, mixed>
     */
    public function review(array $context): array
    {
        $operation = $context['operation'] ?? null;
        if (! $operation instanceof ArbiterOperationContext) {
            throw new InvalidArgumentException('Completeness arbiter operation context is required.');
        }
        unset($context['operation']);
        $payload = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $systemPrompt = $this->systemPrompt();
        if ($this->conservativeInputTokenCount($systemPrompt, $payload) > $this->maxInputTokens) {
            throw new InvalidArgumentException('Completeness arbiter input exceeds the configured token limit.');
        }
        $correlationId = AiOperationContext::deterministicId('completeness|'.json_encode([
            'organization_id' => $operation->organizationId,
            'session_id' => $operation->sessionId,
            'input_version' => $operation->inputVersion,
            'input_hash' => $context['input_hash'] ?? '',
            'prompt_version' => $this->configuredPromptVersion,
            'schema_version' => $this->schemaVersion,
            'model' => $this->configuredModel,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $attempt = new AiOperationContext(
            $correlationId,
            AiPhysicalAttemptIdentity::fromParts(
                $operation->checkpointClaimToken,
                $this->configuredModel,
                $operation->attemptOrdinal,
                $operation->inputVersion.'|'.$this->configuredPromptVersion,
            ),
            $operation->organizationId,
            $operation->projectId,
            $operation->sessionId,
            'validate_draft',
            'completeness_review',
            $operation->attemptOrdinal,
        );
        $price = $this->budgetAuthorizer->authorize(
            $attempt,
            $this->wire->provider(),
            $this->configuredModel,
            $this->maxInputTokens,
            $this->maxOutputTokens,
        );
        $started = hrtime(true);
        $status = 'connection_failed';
        $httpCode = null;
        $response = [];
        $wireClaimed = false;
        try {
            if (! $this->budgetAuthorizer->claimWire($attempt->attemptId)) {
                throw new RerankWireException('wire_replay_forbidden');
            }
            $wireClaimed = true;
            $response = $this->wire->call($this->configuredModel, [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $payload],
            ], [
                'profile' => 'json',
                'temperature' => 0,
                'max_tokens' => $this->maxOutputTokens,
                'timeout' => $this->timeoutSeconds,
            ]);
            $content = $this->normalizedContent((string) ($response['content'] ?? ''));
            $decoded = json_decode($content, true);
            $inputTokens = max(0, (int) ($response['input_tokens'] ?? 0));
            $outputTokens = max(0, (int) ($response['output_tokens'] ?? 0));
            if (! is_array($decoded) || ($response['model'] ?? null) !== $this->configuredModel
                || $inputTokens > $this->maxInputTokens || $outputTokens > $this->maxOutputTokens) {
                throw new RerankWireException('malformed_response');
            }
            $response['content'] = $content;
            $status = 'succeeded';

            return [...$decoded, 'input_tokens' => $inputTokens, 'output_tokens' => $outputTokens];
        } catch (RerankWireException $exception) {
            $status = $exception->attemptStatus === 'wire_replay_forbidden' ? 'connection_failed' : $exception->attemptStatus;
            $httpCode = $exception->httpCode;
            throw $exception;
        } finally {
            if ($wireClaimed) {
                $this->record($attempt, $status, $httpCode, $response, $started, $price);
            }
        }
    }

    private function systemPrompt(): string
    {
        return 'Return only JSON with outcome (passed, targeted_rebuild, confirmed_scope_only, human_review) and findings. Each finding must contain scope_key, package_keys, evidence_refs, action (rebuild or review), and reason_code (missing_component, evidence_required, quantity_unconfirmed). Never invent references or write free text.';
    }

    private function normalizedContent(string $content): string
    {
        $content = trim($content);
        if (is_array(json_decode($content, true))) {
            return $content;
        }
        if (preg_match('/\A```(?:json)?[ \t]*\R(?<json>[\s\S]+)\R```[ \t]*\z/i', $content, $matches) !== 1) {
            return $content;
        }

        return trim((string) ($matches['json'] ?? ''));
    }

    private function conservativeInputTokenCount(string $systemPrompt, string $payload): int
    {
        return strlen($systemPrompt) + strlen($payload) + 16;
    }

    /** @param array<string, mixed> $response */
    private function record(AiOperationContext $context, string $status, ?int $httpCode, array $response, int $started, AiPriceSnapshot $price): void
    {
        try {
            $usageAvailable = ($response['usage_available'] ?? false) === true;
            $this->usageStore->record(new AiUsageData(
                context: $context,
                provider: $this->wire->provider(),
                requestedModel: $this->configuredModel,
                reportedModel: is_string($response['model'] ?? null) ? $response['model'] : null,
                status: in_array($status, ['succeeded', 'http_failed', 'connection_failed', 'malformed_response'], true)
                    ? $status
                    : 'connection_failed',
                durationMs: (int) max(0, round((hrtime(true) - $started) / 1_000_000)),
                usageStatus: $usageAvailable ? 'measured' : 'unavailable',
                inputTokens: $usageAvailable ? max(0, (int) ($response['input_tokens'] ?? 0)) : 0,
                outputTokens: $usageAvailable ? max(0, (int) ($response['output_tokens'] ?? 0)) : 0,
                httpCode: $httpCode,
                priceSnapshot: $price,
            ));
        } catch (Throwable $exception) {
            try {
                Log::error('[EstimateGeneration] Completeness arbiter usage recording failed', [
                    'attempt_id' => $context->attemptId,
                    'exception_class' => $exception::class,
                ]);
            } catch (Throwable) {
            }

            throw new RuntimeException('usage_recording_failed', previous: $exception);
        }
    }
}
