<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Credits;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPricingCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Models\Credits\AICreditQuote;
use App\Models\Organization;
use App\Models\User;
use App\Services\Credits\AICreditService;
use App\Services\Credits\EstimatorCreditRequest;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;

final readonly class EstimatorCreditOperations
{
    public function __construct(private Connection $database, private AICreditService $credits,
        private EstimateGenerationActionAuthorization $authorization, private AiPricingCatalog $prices,
        private EstimatorProcessingPlan $plans, private EvaluationInputFingerprint $inputs, private EstimatorKnownContextSnapshot $knownInputs) {}

    public function quote(User $actor, int $projectId, int $sessionId, int $stateVersion, string $requestId, string $kind, array $parameters = []): array
    {
        if (! Str::isUuid($requestId)) {
            throw new DomainException('estimator_credit_request_id_invalid');
        }

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $stateVersion, $requestId, $kind, $parameters): array {
            $session = $this->session($actor, $projectId, $sessionId);
            $this->authorization->authorize($actor, $session, $kind === 'dialogue' ? 'estimate_generation.review' : 'estimate_generation.generate');
            app(EstimatorProductAccess::class)->requireAvailable((int) $session->organization_id);
            if (! EstimatorCreditPolicy::enabledForOrganization((int) $session->organization_id)) {
                throw new DomainException('estimator_credit_legacy_balance_requires_reconciliation');
            }
            if ((int) $session->state_version !== $stateVersion || $session->status->value === 'archived'
                || in_array($session->status->value, ['generating', 'applying', 'applied'], true)
                || ($session->status->value === 'failed' && $session->resume_status?->value !== 'generating')) {
                throw new StaleEstimateGenerationState($sessionId, $stateVersion);
            }
            if ($kind === 'documents' && ! in_array($session->status->value, ['draft', 'processing_documents'], true)) {
                throw new StaleEstimateGenerationState($sessionId, $stateVersion);
            }
            $activeDocuments = $this->database->table('estimate_generation_credit_operations')->where('organization_id', $session->organization_id)
                ->where('project_id', $projectId)->where('session_id', $sessionId)->where('kind', 'documents')->whereIn('status', ['reserved', 'running'])->first(['operation_id']);
            if ($activeDocuments !== null && $activeDocuments->operation_id !== strtolower($requestId)) {
                throw new DomainException('estimator_credit_documents_processing_active');
            }
            $normalized = $this->parameters($session, $kind, $parameters);
            $existing = $this->database->table('estimate_generation_credit_operations')->where('operation_id', strtolower($requestId))->lockForUpdate()->first();
            $organization = Organization::query()->findOrFail($session->organization_id);
            app(\App\Services\Credits\AICreditCommercialService::class)->reconcileCurrentPaidPeriod($organization);
            if ($existing !== null) {
                $request = $this->replayRequest($actor, $session, $existing, $kind, $normalized);
                $plan = json_decode($existing->processing_plan, true, 64, JSON_THROW_ON_ERROR);
                $payload = $this->credits->replayEstimatorQuote($organization, $actor, (int) $existing->quote_id, $request);
            } else {
                [$request, $plan] = $this->capture($actor, $session, strtolower($requestId), $kind, $normalized);
                $payload = $this->credits->quoteEstimator($organization, $actor, $request);
            }
            $quote = AICreditQuote::query()->where('public_id', $payload['quote_id'])->where('organization_id', $organization->id)->firstOrFail();
            if ($existing === null) {
                $this->database->table('estimate_generation_credit_operations')->insert([
                    'operation_id' => $request->operationId, 'organization_id' => $request->organizationId,
                    'project_id' => $projectId, 'session_id' => $sessionId, 'actor_user_id' => (int) $actor->id,
                    'kind' => $kind, 'status' => 'quoted', 'approved_state_version' => $stateVersion,
                    'input_hash' => $request->inputHash, 'source_version' => $request->sourceVersion,
                    'manifest_hash' => $request->manifestHash, 'plan_hash' => $request->planHash,
                    'processing_plan' => CanonicalPipelineJson::encode($plan), 'credit_request' => CanonicalPipelineJson::encode($request->identity()),
                    'quote_id' => $quote->id, 'maximum_provider_cost_micro_rub' => $request->maximumProviderCostMicroRub,
                    'maximum_calls' => $request->maximumCalls, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $balance = $this->credits->balance($organization);

            return [...$payload, 'operation_id' => $request->operationId, 'state_version' => $stateVersion,
                'operation_kind' => $kind, 'maximum_calls' => $request->maximumCalls, 'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna',
                'available_units_minor' => $balance['available_minor'],
                'reconciliation_held_units_minor' => $balance['reconciliation_held_minor']];
        }, 3);
    }

    /** Caller holds the session transaction; state transition, reserve and queued receipt commit together. */
    public function begin(User $actor, EstimateGenerationSession $session, int $stateVersion, string $requestId, string $quoteId, string $kind, array $parameters = []): string
    {
        if ($this->database->transactionLevel() < 1 || ! Str::isUuid($requestId) || ! Str::isUuid($quoteId)) {
            throw new DomainException('estimator_credit_begin_requires_transaction');
        }
        $qaGuard = app(\App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorQaSupplierExpenseGuard::class);
        $qaGuard->lockForReservation();
        $session = $this->session($actor, (int) $session->project_id, (int) $session->id);
        $this->authorization->authorize($actor, $session, $kind === 'dialogue' ? 'estimate_generation.review' : 'estimate_generation.generate');
        app(EstimatorProductAccess::class)->requireAvailable((int) $session->organization_id);
        if (in_array($session->status->value, ['applied', 'archived'], true)) {
            throw new StaleEstimateGenerationState((int) $session->id, $stateVersion);
        }
        if (! EstimatorCreditPolicy::enabledForOrganization((int) $session->organization_id)) {
            throw new DomainException('estimator_credit_legacy_balance_requires_reconciliation');
        }
        $operation = $this->database->table('estimate_generation_credit_operations')->where('operation_id', strtolower($requestId))
            ->where('organization_id', $session->organization_id)->where('project_id', $session->project_id)->where('session_id', $session->id)
            ->where('actor_user_id', $actor->id)->where('kind', $kind)->lockForUpdate()->first();
        $quote = AICreditQuote::query()->where('public_id', strtolower($quoteId))->where('organization_id', $session->organization_id)
            ->where('user_id', $actor->id)->first();
        if ($operation === null || $quote === null || (int) $quote->id !== (int) $operation->quote_id
            || (int) $operation->approved_state_version !== $stateVersion
            || ! in_array($operation->status, ['quoted', 'reserved', 'running'], true)) {
            throw new DomainException('estimator_credit_quote_not_approved');
        }
        $plan = json_decode($operation->processing_plan, true, 64, JSON_THROW_ON_ERROR);
        if (CanonicalPipelineJson::encode($plan['operation_parameters'] ?? []) !== CanonicalPipelineJson::encode($this->parameters($session, $kind, $parameters))) {
            throw new DomainException('estimator_credit_operation_parameters_changed');
        }
        if ($operation->status !== 'quoted') {
            return (string) $operation->operation_id;
        }
        app(EstimatorDocumentCostAllocation::class)->validate($session, $plan);
        if ((int) $session->state_version !== $stateVersion || ! hash_equals($operation->input_hash, $this->inputs->fromSession($session))) {
            throw new StaleEstimateGenerationState((int) $session->id, $stateVersion);
        }
        if (isset($plan['known_context']) && is_array($plan['known_context'])) {
            $current = $this->knownInputs->capture($session, (bool) $plan['complex'], $plan['text_payload_policy'] ?? \App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorTextWirePolicy::legacyCapture(),
                $plan['operation_parameters']['local_estimate_key'] ?? null, $plan['known_context']['role_limits'] ?? null);
            if (! hash_equals((string) $plan['known_context']['fingerprint'], $current['fingerprint'])) {
                throw new DomainException('estimator_known_context_changed');
            }
        }
        $identity = json_decode($operation->credit_request, true, 64, JSON_THROW_ON_ERROR);
        $request = $this->request($identity);
        $qaGuard->assertReservationBudget($plan, (int) $quote->max_units_minor);
        $reservation = $this->credits->beginEstimator(Organization::query()->findOrFail($session->organization_id), $actor, $quoteId, $request);
        $this->database->table('estimate_generation_credit_operations')->where('operation_id', $operation->operation_id)
            ->update(['reservation_id' => $reservation->id, 'status' => 'reserved', 'updated_at' => now()]);
        if ($kind !== 'dialogue') {
            app(EstimatorDocumentCreditBinding::class)->bind($session, (string) $operation->operation_id);
        }

        return (string) $operation->operation_id;
    }

    private function session(User $actor, int $projectId, int $sessionId): EstimateGenerationSession
    {
        return EstimateGenerationSession::query()->useWritePdo()->where('organization_id', $actor->current_organization_id)
            ->where('project_id', $projectId)->whereKey($sessionId)->lockForUpdate()->firstOrFail();
    }

    private function capture(User $actor, EstimateGenerationSession $session, string $operationId, string $kind, array $parameters): array
    {
        if ($kind === 'dialogue' && ($parameters['idempotency_key'] ?? null) !== $operationId) {
            throw new DomainException('estimator_credit_dialogue_request_id_mismatch');
        }
        [$manifest, $rasterUnits] = $this->manifest($session, $kind);
        $inputHash = $this->inputs->fromSession($session);
        $tariff = $this->prices->resolve('estimate_composition', 'timeweb', 'openai/gpt-6-luna', new DateTimeImmutable);
        $workItems = min(2000, max(32, $this->database->table('estimate_generation_quantity_takeoffs')->where('session_id', $session->id)
            ->where('organization_id', $session->organization_id)->where('project_id', $session->project_id)->count() * 3,
            count($session->input_payload['selected_sections'] ?? []) * 32));
        $complex = count($manifest) > 1 || ($session->input_payload['profile_id'] ?? null) === 'industrial';
        $known = in_array($kind, ['documents', 'dialogue'], true) ? null : $this->knownInputs->capture($session, $complex,
            localEstimateKey: $parameters['local_estimate_key'] ?? null);
        $workItems = max($workItems, $known['known_work_count'] ?? 0);
        $plan = $this->plans->build($kind, $rasterUnits, $workItems, $complex, $tariff,
            ($session->input_payload['regional_context']['normative_rerank_requested'] ?? null) === true, $known['part_limits'] ?? []);
        if ($known !== null) {
            $plan['known_context'] = $known;
        }
        $plan['documents'] = $manifest;
        if (! in_array($kind, ['documents', 'dialogue'], true)) {
            $plan['document_costs'] = app(EstimatorDocumentCostAllocation::class)->capture($session);
            $plan['maximum_provider_cost_micro_rub'] = \Brick\Math\BigInteger::of($plan['maximum_provider_cost_micro_rub'])
                ->plus($plan['document_costs']['cost_micro_rub'])->toInt();
        }
        if ($parameters !== []) {
            $plan['operation_parameters'] = $parameters;
        }
        $qaBudget = app(\App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorQaSupplierExpenseGuard::class)->quoteBudget($plan['document_costs'] ?? []);
        if ($qaBudget !== null) {
            $plan = $this->plans->capForQaBudget($plan, $qaBudget);
        }
        $manifestHash = hash('sha256', CanonicalPipelineJson::encode($manifest));
        $identity = ['organization_id' => (int) $session->organization_id, 'project_id' => (int) $session->project_id,
            'session_id' => (int) $session->id, 'actor_user_id' => (int) $actor->id, 'operation_id' => $operationId, 'operation_kind' => $kind,
            'state_version' => (int) $session->state_version, 'evaluation_input_hash' => $inputHash,
            'source_input_version' => 'sha256:'.$inputHash, 'manifest_hash' => $manifestHash,
            'processing_plan_hash' => hash('sha256', CanonicalPipelineJson::encode($plan)),
            'maximum_provider_cost_micro_rub' => $plan['maximum_provider_cost_micro_rub'], 'maximum_calls' => $plan['maximum_calls'],
            'supplier_tariff' => $tariff->toArray()];

        return [$this->request($identity), $plan];
    }

    private function manifest(EstimateGenerationSession $session, string $kind): array
    {
        $documents = $session->documents()->where('organization_id', $session->organization_id)->where('project_id', $session->project_id)
            ->where('status', '<>', 'ignored')->orderBy('id')->get();
        $manifest = [];
        $rasterUnits = 0;
        foreach ($kind === 'dialogue' ? [] : $documents as $document) {
            $source = DocumentSourceVersion::fromDocument($document);
            $units = $this->database->table('estimate_generation_processing_units')->where('organization_id', $session->organization_id)
                ->where('project_id', $session->project_id)->where('session_id', $session->id)->where('document_id', $document->id)
                ->where('source_version', $source)->orderBy('id')->get(['id', 'unit_type', 'unit_index', 'status', 'locator', 'metadata']);
            $manifest[] = ['document_id' => (int) $document->id, 'source_version' => $source,
                'mime_type' => $document->mime_type, 'units' => $units->map(static fn ($unit): array => [
                    'id' => (int) $unit->id, 'type' => $unit->unit_type, 'index' => (int) $unit->unit_index,
                    'processing_lineage_id' => json_decode($unit->metadata, true, 64, JSON_THROW_ON_ERROR)['processing_attempt_id'] ?? null,
                    'locator_hash' => hash('sha256', CanonicalPipelineJson::encode(json_decode($unit->locator, true, 64, JSON_THROW_ON_ERROR)))])->all()];
            $pending = $units->filter(static fn ($unit): bool => ! in_array($unit->status, ['completed', 'ignored'], true));
            if ($units->isEmpty() && ! in_array($document->status, ['processed', 'completed'], true)) {
                if (str_starts_with((string) $document->mime_type, 'image/') || $document->mime_type === 'application/pdf') {
                    throw new DomainException('estimator_credit_document_preparation_required');
                }
            }
            $rasterUnits += $pending->filter(static fn ($unit): bool => \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitPaidWirePolicy::requiresPaidWire(
                \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType::from($unit->unit_type),
                json_decode($unit->locator, true, 64, JSON_THROW_ON_ERROR)))->count();
        }

        return [$manifest, $rasterUnits];
    }

    private function replayRequest(User $actor, EstimateGenerationSession $session, \stdClass $operation, string $kind, array $parameters): EstimatorCreditRequest
    {
        $request = $this->request(json_decode($operation->credit_request, true, 64, JSON_THROW_ON_ERROR));
        $plan = json_decode($operation->processing_plan, true, 64, JSON_THROW_ON_ERROR);
        if ((int) $operation->organization_id !== (int) $session->organization_id || (int) $operation->project_id !== (int) $session->project_id
            || (int) $operation->session_id !== (int) $session->id || (int) $operation->actor_user_id !== (int) $actor->id
            || $operation->kind !== $kind || (int) $operation->approved_state_version !== (int) $session->state_version
            || $request->organizationId !== (int) $operation->organization_id || $request->projectId !== (int) $operation->project_id
            || $request->sessionId !== (int) $operation->session_id || $request->actorUserId !== (int) $actor->id
            || $request->operationId !== $operation->operation_id || $request->kind !== $kind || $request->stateVersion !== (int) $operation->approved_state_version
            || $request->maximumCalls !== (int) $operation->maximum_calls || $request->maximumProviderCostMicroRub !== (int) $operation->maximum_provider_cost_micro_rub
            || ! hash_equals($request->planHash, $operation->plan_hash)
            || ! hash_equals($request->planHash, hash('sha256', CanonicalPipelineJson::encode($plan)))
            || ! hash_equals($request->inputHash, $operation->input_hash) || ! hash_equals($request->sourceVersion, $operation->source_version)
            || CanonicalPipelineJson::encode($plan['operation_parameters'] ?? []) !== CanonicalPipelineJson::encode($parameters)) {
            throw new DomainException('estimator_credit_request_id_conflict');
        }
        if (! hash_equals($request->inputHash, $this->inputs->fromSession($session))) {
            throw new StaleEstimateGenerationState((int) $session->id, $request->stateVersion);
        }
        app(EstimatorDocumentCostAllocation::class)->validate($session, $plan);
        [$manifest] = $this->manifest($session, $kind);
        if (! hash_equals($request->manifestHash, $operation->manifest_hash)
            || ! hash_equals($request->manifestHash, hash('sha256', CanonicalPipelineJson::encode($manifest)))) {
            throw new DomainException('estimator_credit_manifest_changed');
        }
        if (isset($plan['known_context']) && is_array($plan['known_context'])) {
            $current = $this->knownInputs->capture($session, (bool) $plan['complex'], $plan['text_payload_policy'] ?? \App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorTextWirePolicy::legacyCapture(),
                $plan['operation_parameters']['local_estimate_key'] ?? null, $plan['known_context']['role_limits'] ?? null);
            if (! hash_equals((string) $plan['known_context']['fingerprint'], $current['fingerprint'])) {
                throw new DomainException('estimator_known_context_changed');
            }
        }

        return $request;
    }

    private function request(array $identity): EstimatorCreditRequest
    {
        return new EstimatorCreditRequest($identity['organization_id'], $identity['project_id'], $identity['session_id'], $identity['actor_user_id'],
            $identity['operation_id'], $identity['operation_kind'], $identity['state_version'], $identity['evaluation_input_hash'],
            $identity['source_input_version'], $identity['manifest_hash'], $identity['processing_plan_hash'],
            $identity['maximum_provider_cost_micro_rub'], $identity['maximum_calls'], $identity['supplier_tariff']);
    }

    private function parameters(EstimateGenerationSession $session, string $kind, array $parameters): array
    {
        if ($kind === 'dialogue') {
            $command = $parameters['command'] ?? null;
            $key = $parameters['idempotency_key'] ?? null;
            if (! is_string($command) || trim($command) === '' || mb_strlen($command) > 4000
                || ! is_string($key) || strlen($key) < 8 || strlen($key) > 128
                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]+\z/D', $key) !== 1
                || count($parameters) !== 2) {
                throw new DomainException('estimator_credit_operation_parameters_invalid');
            }
            $context = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateCommandContextBuilder::class)->build($session);

            return ['command_hash' => hash('sha256', $command), 'idempotency_key' => $key, 'context_fingerprint' => $context['fingerprint']];
        }
        if ($kind !== 'rebuild') {
            if ($parameters !== []) {
                throw new DomainException('estimator_credit_operation_parameters_invalid');
            }

            return [];
        }
        $key = $parameters['local_estimate_key'] ?? null;
        if (! is_string($key) || trim($key) === '' || strlen($key) > 255 || array_keys($parameters) !== ['local_estimate_key']) {
            throw new DomainException('estimator_credit_operation_parameters_invalid');
        }
        $matches = array_values(array_filter($session->draft_payload['local_estimates'] ?? [],
            static fn (array $estimate): bool => ($estimate['key'] ?? null) === $key));
        if (count($matches) !== 1 || (($session->input_payload['evaluation_mode'] ?? null) === 'universal'
            && ! in_array(\App\BusinessModules\Addons\EstimateGeneration\Planning\EvaluationSectionMap::forPackage($key),
                $session->input_payload['selected_sections'] ?? [], true))) {
            throw new DomainException('estimator_credit_rebuild_target_invalid');
        }

        return ['local_estimate_key' => $key];
    }
}
