<?php

declare(strict_types=1);

namespace App\Services\Credits;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
use DomainException;
use Illuminate\Support\Str;

/** Built from a scoped server operation and its finite processing plan, never directly from HTTP request data. */
final readonly class EstimatorCreditRequest
{
    public const PROFILE = 'estimator_luna:v1';

    public function __construct(public int $organizationId, public int $projectId, public int $sessionId, public int $actorUserId,
        public string $operationId, public string $kind, public int $stateVersion, public string $inputHash,
        public string $sourceVersion, public string $manifestHash, public string $planHash,
        public int $maximumProviderCostMicroRub, public int $maximumCalls, public array $supplierTariff)
    {
        if (min($organizationId, $projectId, $sessionId, $actorUserId) < 1 || $stateVersion < 0 || ! Str::isUuid($operationId)
            || ! in_array($kind, ['generation', 'analysis', 'dialogue', 'documents', 'rebuild'], true)
            || $maximumProviderCostMicroRub < 1 || $maximumCalls < 1 || $maximumCalls > 10000
            || preg_match('/\Asha256:[a-f0-9]{64}\z/', $sourceVersion) !== 1
            || ($supplierTariff['currency'] ?? null) !== 'RUB' || ! is_string($supplierTariff['version'] ?? null)
            || trim($supplierTariff['version']) === '') {
            throw new DomainException('estimator_credit_request_invalid');
        }
        foreach ([$inputHash, $manifestHash, $planHash] as $hash) {
            if (preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1) {
                throw new DomainException('estimator_credit_identity_invalid');
            }
        }
        $snapshot = AiPriceSnapshot::fromArray($supplierTariff);
        if (! $snapshot->available || $snapshot->currency !== 'RUB' || $snapshot->reasoningMode !== 'included_in_output') {
            throw new DomainException('estimator_credit_supplier_tariff_invalid');
        }
        foreach (['reasoning_per_million', 'image_unit', 'page_unit'] as $rate) {
            if (! isset($snapshot->rates[$rate])) {
                throw new DomainException('estimator_credit_supplier_tariff_incomplete');
            }
        }
    }

    public function requestKey(): string
    {
        return 'estimator:'.$this->kind.':'.strtolower($this->operationId);
    }

    public function identity(): array
    {
        return ['contract_version' => 'estimator-credits:v1', 'domain' => 'estimate_generation', 'operation_kind' => $this->kind,
            'operation_id' => strtolower($this->operationId), 'organization_id' => $this->organizationId, 'project_id' => $this->projectId,
            'session_id' => $this->sessionId, 'actor_user_id' => $this->actorUserId, 'state_version' => $this->stateVersion,
            'evaluation_input_hash' => $this->inputHash, 'source_input_version' => $this->sourceVersion, 'manifest_hash' => $this->manifestHash,
            'processing_plan_hash' => $this->planHash, 'settings_version' => self::PROFILE, 'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna',
            'maximum_provider_cost_micro_rub' => $this->maximumProviderCostMicroRub, 'maximum_calls' => $this->maximumCalls,
            'supplier_tariff' => $this->supplierTariff];
    }
}
