<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Factory;

use App\BusinessModules\Addons\EstimateGeneration\Application\Review\EstimatePrerequisiteBacklog;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

final readonly class FactoryInspectionStore
{
    public const POLICY = 'factory-input-inspection:v1';

    public const MAX_STEPS = 64;

    public const MAX_ATTEMPTS = 3;

    public function __construct(private Connection $database, private FactoryInspectionInput $inputs,
        private EstimateGenerationActionAuthorization $authorization) {}

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

    public function claim(User $actor, int $projectId, int $sessionId, int $expectedVersion, string $requestId): FactoryInspectionClaim|array
    {
        if ($expectedVersion < 0 || $expectedVersion > 2147483647 || ! Str::isUuid($requestId) || $requestId === '00000000-0000-0000-0000-000000000000') {
            throw ValidationException::withMessages(['request_id' => trans_message('estimate_generation.factory.invalid_request')]);
        }

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $expectedVersion, $requestId): FactoryInspectionClaim|array {
            $session = $this->session($actor, $projectId, $sessionId, 'estimate_generation.review');
            $fingerprint = hash('sha256', CanonicalPipelineJson::encode([self::POLICY, (int) $actor->id, $expectedVersion]));
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
                $this->finish($session, $active->id, self::stop('stale', ['expired_checkpoint_superseded']), $now);
            }
            if ($checkpoint === null && ((int) $session->state_version !== $expectedVersion || ! $this->editable($session))) {
                throw new StaleEstimateGenerationState($sessionId, $expectedVersion);
            }
            if ($run !== null && ($run->policy_version !== self::POLICY || ($checkpoint === null && (int) $run->step_count >= self::MAX_STEPS))) {
                throw ValidationException::withMessages(['request_id' => trans_message('estimate_generation.factory.step_limit')]);
            }
            $capture = $this->inputs->capture($session);
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
                    $this->finish($session, $checkpoint->id, self::stop($outcome), $now);

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
                    'owner_token' => (string) Str::uuid(), 'lease_expires_at' => $now->addSeconds(60),
                    'attempt_count' => 1, 'result' => '{}', 'created_at' => $now, 'updated_at' => $now]);
                $this->runs($session)->where('id', $run->id)->update(['step_count' => $step, 'updated_at' => $now]);
                $run = $this->runs($session)->where('id', $run->id)->first();
                $checkpoint = $this->checkpoints($session)->where('id', $id)->first();
                if (! $capture['within_budget']) {
                    $this->finish($session, $id, self::stop('unavailable', $capture['limitations']), $now);

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
        if (strlen($encoded) > 65536 || ! in_array($result['outcome'] ?? null, ['awaiting_input', 'source_review_required', 'planning_required', 'unavailable'], true)
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
                ->where('status', 'running')->where('owner_token', $claim->ownerToken)->where('attempt_count', $claim->attempt)
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
            (int) $checkpoint->attempt_count, $checkpoint->input_fingerprint, $capture['model_token'], $session, $capture['snapshot']);
    }

    private function finish(EstimateGenerationSession $session, string $id, array $result, CarbonImmutable $now): void
    {
        $this->checkpoints($session)->where('id', $id)->update(['status' => $result['outcome'],
            'result' => CanonicalPipelineJson::encode($result), 'owner_token' => null, 'lease_expires_at' => null,
            'finished_at' => $now, 'updated_at' => $now]);
    }

    private function present(EstimateGenerationSession $session, ?stdClass $run, ?stdClass $checkpoint, ?array $capture = null): array
    {
        $fresh = false;
        if ($checkpoint !== null && ! in_array($checkpoint->status, ['running', 'stale', 'exhausted', 'unavailable'], true)
            && $run?->policy_version === self::POLICY && $this->editable($session)) {
            $capture ??= $this->inputs->capture($session);
            $fresh = $capture['within_budget'] && (int) $session->state_version === (int) $checkpoint->expected_state_version
                && hash_equals($checkpoint->input_fingerprint, $capture['fingerprint']);
        }

        return ['contract_version' => self::POLICY, 'session_id' => (int) $session->id, 'state_version' => (int) $session->state_version,
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
