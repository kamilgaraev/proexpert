<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EstimateResultClass;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationResultValidator;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\SystemAdmin;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class SaveEvaluationRevision
{
    public function __construct(private Connection $database, private EstimateGenerationActionAuthorization $authorization, private EvaluationInputFingerprint $inputs, private EvaluationResultValidator $results) {}

    public function save(User|SystemAdmin $actor, int $organizationId, int $projectId, int $sessionId, int $stateVersion, string $operationId, string $inputHash, array $result, string $purpose = 'generation'): array
    {
        if (! in_array($purpose, ['generation', 'arithmetic_recalculation'], true) || ! Str::isUuid($operationId) || preg_match('/\A[a-f0-9]{64}\z/', $inputHash) !== 1
            || EstimateResultClass::tryFrom($result['result_class'] ?? '') === null
            || ! is_string($result['profile_version'] ?? null)
            || strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 16 * 1024 * 1024) {
            throw new InvalidArgumentException('evaluation_revision_contract_invalid');
        }
        foreach (['progress', 'balance', 'reserved_units', 'charged_units', 'quote_id'] as $key) {
            if (array_key_exists($key, $result)) {
                throw new InvalidArgumentException('evaluation_business_content_contains_operational_state');
            }
        }
        $this->results->validate($result);
        $contentHash = hash('sha256', json_encode($this->canonical($result), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $this->database->transaction(function () use ($actor, $purpose, $organizationId, $projectId, $sessionId, $stateVersion, $operationId, $inputHash, $result, $contentHash): array {
            $session = EstimateGenerationSession::query()->where('organization_id', $organizationId)->where('project_id', $projectId)
                ->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($actor, $session, $purpose === 'generation' ? 'estimate_generation.generate' : 'estimate_generation.review');
            $query = $this->database->table('estimate_generation_evaluation_revisions')->where('organization_id', $organizationId)
                ->where('project_id', $projectId)->where('session_id', $sessionId);
            $existing = (clone $query)->where('operation_id', $operationId)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->input_hash, $inputHash) || ! hash_equals($existing->content_hash, $contentHash)) {
                    throw new InvalidArgumentException('evaluation_operation_identity_collision');
                }

                return ['revision_id' => $existing->public_id, 'revision' => (int) $existing->revision, 'content_hash' => $contentHash];
            }
            if ($session->state_version !== $stateVersion || $session->status->isTerminal() || $session->status->value === 'applying') {
                throw new StaleEstimateGenerationState($sessionId, $stateVersion);
            }
            if (! hash_equals($inputHash, $this->inputs->fromSession($session))) {
                throw new InvalidArgumentException('evaluation_input_changed');
            }
            $publicId = (string) Str::uuid();
            $revision = (int) $query->max('revision') + 1;
            $this->database->table('estimate_generation_evaluation_revisions')->insert([
                'public_id' => $publicId, 'organization_id' => $organizationId, 'project_id' => $projectId, 'session_id' => $sessionId,
                'revision' => $revision, 'input_state_version' => $stateVersion, 'operation_id' => $operationId, 'input_hash' => $inputHash,
                'content_hash' => $contentHash, 'result_class' => $result['result_class'], 'profile_version' => $result['profile_version'],
                'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);

            return ['revision_id' => $publicId, 'revision' => $revision, 'content_hash' => $contentHash];
        }, 3);
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }

        return $value;
    }
}
