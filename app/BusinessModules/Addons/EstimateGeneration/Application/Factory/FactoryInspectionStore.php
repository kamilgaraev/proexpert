<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Factory;

use App\BusinessModules\Addons\EstimateGeneration\Application\Review\EstimatePrerequisiteBacklog;
use App\BusinessModules\Addons\EstimateGeneration\Application\Review\UpdateEstimatePrerequisites;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Ramsey\Uuid\Uuid;
use stdClass;

final readonly class FactoryInspectionStore
{
    public const POLICY = 'factory-input-inspection:v1';

    public const MAX_STEPS = 64;

    public const MAX_ATTEMPTS = 3;

    public function __construct(private Connection $database, private FactoryInspectionInput $inputs,
        private EstimateGenerationActionAuthorization $authorization, private FactoryPlanningInput $planning,
        private FactoryPlanningInspector $planningInspector, private UpdateEstimatePrerequisites $prerequisites) {}

    public function current(User $actor, int $projectId, int $sessionId, ?string $requestId = null): array
    {
        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $requestId): array {
            $session = $this->session($actor, $projectId, $sessionId, 'estimate_generation.view');
            $run = $this->runs($session)->first();
            $query = $this->checkpoints($session);
            if ($requestId !== null) {
                $query->where('request_id', $requestId);
            }
            $checkpoint = $run === null ? null : $query->where('run_id', $run->id)->orderByDesc('step_number')->first();

            return $this->present($session, $run, $checkpoint);
        }, 3);
    }

    public function claim(User $actor, int $projectId, int $sessionId, int $expectedVersion, string $requestId,
        string $stepKind = 'inspect_input', ?string $sourceCheckpointId = null): FactoryInspectionClaim|array
    {
        if ($expectedVersion < 0 || $expectedVersion > ($stepKind === 'inspect_plan' ? 2147483646 : 2147483647)
            || ! in_array($stepKind, ['inspect_input', 'inspect_plan'], true)
            || ($stepKind === 'inspect_plan' && ($sourceCheckpointId === null || ! Str::isUuid($sourceCheckpointId)))
            || ($stepKind === 'inspect_input' && $sourceCheckpointId !== null)
            || ! Str::isUuid($requestId) || $requestId === '00000000-0000-0000-0000-000000000000') {
            throw ValidationException::withMessages(['request_id' => trans_message('estimate_generation.factory.invalid_request')]);
        }

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $expectedVersion, $requestId, $stepKind, $sourceCheckpointId): FactoryInspectionClaim|array {
            $session = $this->session($actor, $projectId, $sessionId, 'estimate_generation.review');
            $fingerprint = hash('sha256', CanonicalPipelineJson::encode($stepKind === 'inspect_input'
                ? [self::POLICY, (int) $actor->id, $expectedVersion]
                : [FactoryPlanningInput::CONTRACT, (int) $actor->id, $expectedVersion, $sourceCheckpointId]));
            $run = $this->runs($session)->first();
            $checkpoint = $this->checkpoints($session)->where('request_id', $requestId)->first();
            if ($checkpoint !== null && ! hash_equals($checkpoint->request_fingerprint, $fingerprint)) {
                throw ValidationException::withMessages(['request_id' => trans_message('estimate_generation.factory.request_collision')]);
            }
            if ($checkpoint !== null && $checkpoint->status !== 'running') {
                return $this->present($session, $run, $checkpoint);
            }
            $now = CarbonImmutable::now();
            if ($checkpoint !== null && CarbonImmutable::parse($checkpoint->lease_expires_at)->isAfter($now)) {
                return $this->present($session, $run, $checkpoint);
            }
            $active = $this->checkpoints($session)->where('status', 'running')->first();
            if ($checkpoint === null && $active !== null) {
                if (CarbonImmutable::parse($active->lease_expires_at)->isAfter($now)) {
                    return $this->present($session, $run, $active);
                }
                $this->finish($session, $active->id, $this->stopCheckpoint($session, $active, 'stale', ['expired_checkpoint_superseded']), $now);
            }
            if ($checkpoint === null && ((int) $session->state_version !== $expectedVersion || ! $this->editable($session))) {
                throw new StaleEstimateGenerationState($sessionId, $expectedVersion);
            }
            if ($run !== null && ($run->policy_version !== self::POLICY || ($checkpoint === null && (int) $run->step_count >= self::MAX_STEPS))) {
                throw ValidationException::withMessages(['request_id' => trans_message('estimate_generation.factory.step_limit')]);
            }
            $source = $sourceCheckpointId === null ? null : $this->checkpoints($session)->where('id', $sourceCheckpointId)
                ->where('run_id', $run?->id)->where('step_kind', 'inspect_input')->where('status', 'planning_required')->first();
            if ($stepKind === 'inspect_plan' && ($run === null || $source === null
                || (int) $source->expected_state_version !== $expectedVersion
                || ($checkpoint === null && (int) $source->step_number !== (int) $run->step_count))) {
                throw new StaleEstimateGenerationState($sessionId, $expectedVersion);
            }
            $capture = $stepKind === 'inspect_plan' ? $this->planning->capture($session) : $this->inputs->capture($session);
            if ($stepKind === 'inspect_plan' && $capture['within_budget']
                && ! hash_equals($source->input_fingerprint, $capture['input_fingerprint'])) {
                throw new StaleEstimateGenerationState($sessionId, $expectedVersion);
            }
            if ($run === null) {
                $id = (string) Str::uuid();
                $this->runs($session)->insert(['id' => $id, 'organization_id' => $session->organization_id,
                    'project_id' => $projectId, 'session_id' => $sessionId, 'policy_version' => self::POLICY,
                    'step_count' => 0, 'created_at' => $now, 'updated_at' => $now]);
                $run = $this->runs($session)->where('id', $id)->first();
            }
            if ($checkpoint !== null) {
                $outcome = ! $this->editable($session) || (int) $session->state_version !== $expectedVersion
                    || ! $capture['within_budget'] || ! hash_equals($checkpoint->input_fingerprint, $capture['fingerprint'])
                    ? 'stale' : ((int) $checkpoint->attempt_count >= self::MAX_ATTEMPTS ? 'exhausted' : null);
                if ($outcome !== null) {
                    $this->finish($session, $checkpoint->id, $this->stopCheckpoint($session, $checkpoint, $outcome), $now);

                    return $this->present($session, $run, $this->checkpoints($session)->where('id', $checkpoint->id)->first(), $capture);
                }
            } else {
                $id = (string) Str::uuid();
                $step = (int) $run->step_count + 1;
                $this->checkpoints($session)->insert(['id' => $id, 'run_id' => $run->id,
                    'organization_id' => $session->organization_id, 'project_id' => $projectId, 'session_id' => $sessionId,
                    'request_id' => $requestId, 'actor_id' => $actor->id, 'step_number' => $step,
                    'expected_state_version' => $expectedVersion, 'request_fingerprint' => $fingerprint,
                    'input_fingerprint' => $capture['fingerprint'], 'status' => 'running',
                    'step_kind' => $stepKind, 'source_checkpoint_id' => $sourceCheckpointId,
                    'owner_token' => (string) Str::uuid(), 'lease_expires_at' => $now->addSeconds(60),
                    'attempt_count' => 1, 'result' => '{}', 'created_at' => $now, 'updated_at' => $now]);
                $this->runs($session)->where('id', $run->id)->update(['step_count' => $step, 'updated_at' => $now]);
                $run = $this->runs($session)->where('id', $run->id)->first();
                $checkpoint = $this->checkpoints($session)->where('id', $id)->first();
                if (! $capture['within_budget']) {
                    $created = $this->checkpoints($session)->where('id', $id)->first();
                    $this->finish($session, $id, $this->stopCheckpoint($session, $created, 'unavailable', $capture['limitations']), $now);

                    return $this->present($session, $run, $this->checkpoints($session)->where('id', $id)->first(), $capture);
                }

                return $this->claimValue($session, $run, $checkpoint, $capture);
            }
            $this->checkpoints($session)->where('id', $checkpoint->id)->update([
                'owner_token' => (string) Str::uuid(), 'lease_expires_at' => $now->addSeconds(60),
                'attempt_count' => (int) $checkpoint->attempt_count + 1, 'updated_at' => $now]);

            return $this->claimValue($session, $run, $this->checkpoints($session)->where('id', $checkpoint->id)->first(), $capture);
        }, 3);
    }

    public function complete(FactoryInspectionClaim $claim, array $result): bool
    {
        $encoded = CanonicalPipelineJson::encode($result);
        if ($claim->stepKind !== 'inspect_input' || strlen($encoded) > 65536 || ! in_array($result['outcome'] ?? null, ['awaiting_input', 'source_review_required', 'planning_required', 'unavailable'], true)
            || ($result['cost_units'] ?? null) !== '0' || ($result['estimate_complete'] ?? null) !== false || ($result['paid_actions_enabled'] ?? null) !== false) {
            throw ValidationException::withMessages(['result' => trans_message('estimate_generation.factory.invalid_result')]);
        }

        return $this->database->transaction(function () use ($claim, $result): bool {
            $scope = $claim->session;
            $session = EstimateGenerationSession::query()->where('organization_id', $scope->organization_id)
                ->where('project_id', $scope->project_id)->whereKey($scope->id)->lockForUpdate()->first();
            if (! $session instanceof EstimateGenerationSession) {
                return false;
            }
            $now = CarbonImmutable::now();
            $run = $this->runs($session)->where('id', $claim->runId)->first();
            $checkpoint = $this->checkpoints($session)->where('id', $claim->checkpointId)->where('run_id', $claim->runId)
                ->where('step_kind', 'inspect_input')->where('status', 'running')->where('owner_token', $claim->ownerToken)->where('attempt_count', $claim->attempt)
                ->where('input_fingerprint', $claim->inputFingerprint)->where('lease_expires_at', '>', $now)->first();
            if ($run === null || $checkpoint === null || $run->policy_version !== self::POLICY
                || (int) $run->step_count !== (int) $checkpoint->step_number || ! $this->editable($session)
                || (int) $session->state_version !== (int) $checkpoint->expected_state_version) {
                return false;
            }
            $capture = $this->inputs->capture($session);
            if (! $capture['within_budget'] || ! hash_equals($claim->inputFingerprint, $capture['fingerprint'])
                || ! hash_equals($claim->modelToken, $capture['model_token'])
                || ! CarbonImmutable::parse($checkpoint->lease_expires_at)->isAfter(CarbonImmutable::now())) {
                return false;
            }
            $this->finish($session, $checkpoint->id, $result, CarbonImmutable::now());

            return true;
        }, 3);
    }

    public static function stop(string $outcome, array $limitations = []): array
    {
        return ['step' => 'inspect_input', 'outcome' => $outcome, 'question_refs' => [], 'total_questions' => 0,
            'truncated' => false, 'limitations' => $limitations, 'estimate_complete' => false,
            'paid_actions_enabled' => false, 'cost_units' => '0'];
    }

    public function completePlanning(User $actor, FactoryInspectionClaim $claim): void
    {
        if ($claim->stepKind !== 'inspect_plan' || $claim->sourceCheckpointId === null || $this->database !== DB::connection()) {
            throw new LogicException('factory_planning_execution_contract_invalid');
        }
        $this->database->transaction(function () use ($actor, $claim): void {
            $session = $this->session($actor, (int) $claim->session->project_id, (int) $claim->session->id, 'estimate_generation.review');
            $checkpoint = $this->checkpoints($session)->where('id', $claim->checkpointId)->where('run_id', $claim->runId)
                ->where('step_kind', 'inspect_plan')->where('source_checkpoint_id', $claim->sourceCheckpointId)
                ->where('actor_id', $actor->id)->where('status', 'running')->where('owner_token', $claim->ownerToken)
                ->where('attempt_count', $claim->attempt)->where('input_fingerprint', $claim->inputFingerprint)
                ->where('lease_expires_at', '>', CarbonImmutable::now())->first();
            $run = $this->runs($session)->where('id', $claim->runId)->where('policy_version', self::POLICY)->first();
            if ($checkpoint === null || $run === null || ! $this->editable($session)
                || (int) $run->step_count !== (int) $checkpoint->step_number
                || (int) $session->state_version !== (int) $checkpoint->expected_state_version) {
                throw new StaleEstimateGenerationState((int) $session->id, (int) $claim->session->state_version);
            }
            $capture = $this->planning->capture($session);
            if (! $capture['within_budget'] || ! hash_equals($capture['fingerprint'], $claim->inputFingerprint)
                || ! hash_equals($capture['model_token'], $claim->modelToken)) {
                throw new StaleEstimateGenerationState((int) $session->id, (int) $checkpoint->expected_state_version);
            }
            $childId = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'most-factory-planning:v1:'.implode(':', [
                $session->organization_id, $session->project_id, $session->id, $checkpoint->request_id,
            ]));
            $this->prerequisites->refresh($actor, (int) $session->project_id, (int) $session->id,
                (int) $checkpoint->expected_state_version, $childId);
            $session->refresh();
            if ((int) $session->state_version !== (int) $checkpoint->expected_state_version + 1) {
                throw new LogicException('factory_planning_refresh_version_invalid');
            }
            $capture = $this->planning->capture($session);
            $result = $this->planningInspector->inspect($session, $claim->sourceCheckpointId, $capture);
            if (strlen(CanonicalPipelineJson::encode($result)) > 65536
                || ! $this->checkpoints($session)->where('id', $claim->checkpointId)->where('run_id', $claim->runId)
                    ->where('status', 'running')->where('owner_token', $claim->ownerToken)->where('attempt_count', $claim->attempt)
                    ->where('lease_expires_at', '>', CarbonImmutable::now())->exists()
                || ! $this->runs($session)->where('id', $claim->runId)->where('step_count', $checkpoint->step_number)->exists()) {
                throw new StaleEstimateGenerationState((int) $session->id, (int) $checkpoint->expected_state_version);
            }
            $this->finish($session, $checkpoint->id, $result, CarbonImmutable::now(), [
                'result_input_fingerprint' => $capture['fingerprint'], 'catalog_fingerprint' => $capture['catalog_fingerprint'],
            ]);
        }, 3);
    }

    private function session(User $actor, int $projectId, int $sessionId, string $permission): EstimateGenerationSession
    {
        $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
            ->where('project_id', $projectId)->whereKey($sessionId)->lockForUpdate()->firstOrFail();
        $this->authorization->authorize($actor, $session, $permission);

        return $session;
    }

    private function editable(EstimateGenerationSession $session): bool
    {
        return config('estimate-generation.universal_enabled', false)
            && ($session->input_payload['evaluation_mode'] ?? null) === 'universal' && EstimatePrerequisiteBacklog::editable($session);
    }

    private function runs(EstimateGenerationSession $session): Builder
    {
        return $this->scoped('estimate_generation_factory_runs', $session);
    }

    private function checkpoints(EstimateGenerationSession $session): Builder
    {
        return $this->scoped('estimate_generation_factory_checkpoints', $session);
    }

    private function scoped(string $table, EstimateGenerationSession $session): Builder
    {
        return $this->database->table($table)->where('organization_id', $session->organization_id)
            ->where('project_id', $session->project_id)->where('session_id', $session->id);
    }

    private function claimValue(EstimateGenerationSession $session, stdClass $run, stdClass $checkpoint, array $capture): FactoryInspectionClaim
    {
        return new FactoryInspectionClaim($run->id, $checkpoint->id, $checkpoint->owner_token,
            (int) $checkpoint->attempt_count, $checkpoint->input_fingerprint, $capture['model_token'], $session, $capture['snapshot'],
            $checkpoint->step_kind, $checkpoint->source_checkpoint_id);
    }

    private function stopCheckpoint(EstimateGenerationSession $session, stdClass $checkpoint, string $outcome, array $limitations = []): array
    {
        return $checkpoint->step_kind === 'inspect_plan'
            ? FactoryPlanningInspector::stop($outcome, $checkpoint->source_checkpoint_id, (int) $session->state_version, $limitations)
            : self::stop($outcome, $limitations);
    }

    private function finish(EstimateGenerationSession $session, string $id, array $result, CarbonImmutable $now, array $binding = []): void
    {
        $this->checkpoints($session)->where('id', $id)->update([...$binding, ...(($result['step'] ?? null) === 'inspect_plan'
            ? ['result_state_version' => $result['result_state_version']] : []), 'status' => $result['outcome'],
            'result' => CanonicalPipelineJson::encode($result), 'owner_token' => null, 'lease_expires_at' => null,
            'finished_at' => $now, 'updated_at' => $now]);
    }

    private function present(EstimateGenerationSession $session, ?stdClass $run, ?stdClass $checkpoint, ?array $capture = null): array
    {
        $fresh = false;
        if ($checkpoint !== null && ! in_array($checkpoint->status, ['running', 'stale', 'exhausted', 'unavailable'], true)
            && $run?->policy_version === self::POLICY && $this->editable($session)) {
            $plan = $checkpoint->step_kind === 'inspect_plan';
            $capture ??= $plan ? $this->planning->capture($session) : $this->inputs->capture($session);
            $fresh = $capture['within_budget'] && (int) $session->state_version === (int) ($plan ? $checkpoint->result_state_version : $checkpoint->expected_state_version)
                && hash_equals($plan ? $checkpoint->result_input_fingerprint : $checkpoint->input_fingerprint, $capture['fingerprint'])
                && (! $plan || hash_equals($checkpoint->catalog_fingerprint, $capture['catalog_fingerprint']));
        }

        return ['contract_version' => $checkpoint?->step_kind === 'inspect_plan' ? FactoryPlanningInput::CONTRACT : self::POLICY,
            'session_id' => (int) $session->id, 'state_version' => (int) $session->state_version,
            'run' => $run === null ? null : ['id' => $run->id, 'policy_version' => $run->policy_version,
                'step_count' => (int) $run->step_count, 'max_steps' => self::MAX_STEPS],
            'checkpoint' => $checkpoint === null ? null : ['id' => $checkpoint->id, 'request_id' => $checkpoint->request_id,
                'step_number' => (int) $checkpoint->step_number, 'expected_state_version' => (int) $checkpoint->expected_state_version,
                'input_fingerprint' => $checkpoint->input_fingerprint, 'status' => $checkpoint->status,
                'attempt_count' => (int) $checkpoint->attempt_count, 'max_attempts' => self::MAX_ATTEMPTS,
                'lease_expires_at' => $checkpoint->lease_expires_at === null ? null : CarbonImmutable::parse($checkpoint->lease_expires_at)->toIso8601String(),
                'result' => $checkpoint->status === 'running' ? null : json_decode($checkpoint->result, true, 512, JSON_THROW_ON_ERROR)],
            'fresh' => $fresh, 'paid_actions_enabled' => false, 'cost_units' => '0'];
    }
}
