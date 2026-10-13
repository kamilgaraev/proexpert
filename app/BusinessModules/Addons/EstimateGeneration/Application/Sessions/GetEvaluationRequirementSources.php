<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\ScopeCompletenessEvaluator;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Validation\ValidationException;

final readonly class GetEvaluationRequirementSources
{
    public function __construct(private Connection $database, private EstimateGenerationActionAuthorization $authorization,
        private ProjectModelRepository $models) {}

    public function handle(User $actor, int $projectId, int $sessionId, array $input): array
    {
        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $input): array {
            $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->whereKey($sessionId)->sharedLock()->firstOrFail();
            $this->authorization->authorize($actor, $session, 'estimate_generation.view');
            if (! config('estimate-generation.universal_enabled', false) || ($session->input_payload['evaluation_mode'] ?? null) !== 'universal') {
                throw ValidationException::withMessages(['requirement' => trans_message('estimate_generation.requirement_sources.unavailable')]);
            }
            $entityId = $input['entity_id'] ?? null;
            $section = $input['section'] ?? null;
            $requirement = $input['requirement'] ?? null;
            $version = $input['state_version'] ?? null;
            $page = $input['page'] ?? 1;
            if (! is_string($entityId) || ! is_string($section) || ! is_string($requirement) || ! is_int($version)
                || ! is_int($page) || $page < 1 || $page > 100000) {
                throw ValidationException::withMessages(['requirement' => trans_message('estimate_generation.requirement_sources.target_invalid')]);
            }
            if ($version !== (int) $session->state_version) {
                throw new StaleEstimateGenerationState($sessionId, $version);
            }
            $allowed = ScopeCompletenessEvaluator::requirementsFor($section,
                (string) ($session->input_payload['construction_type'] ?? 'new_construction'), (string) ($session->input_payload['profile_id'] ?? 'construction'));
            $selected = $session->draft_payload['evaluation_result']['coverage']['selected_scope'][$section] ?? [];
            if (! in_array($entityId, $selected, true) || ! in_array($requirement, $allowed, true)) {
                throw ValidationException::withMessages(['requirement' => trans_message('estimate_generation.requirement_sources.target_invalid')]);
            }
            $dispositions = [];
            if (str_contains($requirement, 'test_program') || str_contains($requirement, 'specification')
                || in_array($requirement, ['technology_requirements', 'industry_work_checklist'], true)) {
                $dispositions[] = 'document_verified';
            }
            if (in_array($requirement, ['demolition', 'waste_removal', 'existing_structures_protection'], true)) {
                $dispositions[] = 'not_applicable';
            }
            $snapshot = $this->models->snapshotForPlanning((int) $session->organization_id, $projectId, $sessionId, 10001)['snapshot'];
            if (count($snapshot->facts) > 10000) {
                throw ValidationException::withMessages(['requirement' => trans_message('estimate_generation.room_identity.fact_limit')]);
            }
            $entities = array_column($snapshot->entities, null, 'id');
            if (! isset($entities[$entityId])) {
                throw ValidationException::withMessages(['requirement' => trans_message('estimate_generation.requirement_sources.target_invalid')]);
            }
            $proofs = array_column($snapshot->evidence, null, 'id');
            $documents = $session->documents()->where('organization_id', $session->organization_id)->where('project_id', $projectId)->get()->keyBy('id');
            $rows = [];
            foreach ($snapshot->facts as $fact) {
                if ($fact->entityId !== $entityId || $fact->type !== $requirement || $fact->origin !== 'document'
                    || $fact->evidenceIds === [] || ! in_array($fact->status, ['confirmed', 'candidate', 'unresolved'], true)) {
                    continue;
                }
                $locators = [];
                $documentId = null;
                $documentName = null;
                foreach ($fact->evidenceIds as $id) {
                    $proof = $proofs[$id] ?? null;
                    $reference = $proof?->sourceReference ?? $proof?->sourceArtifactId;
                    if ($proof === null || $proof->sourceType !== 'document' || $proof->sourceVersion !== $fact->sourceVersion
                        || ! is_string($reference) || preg_match('/\Adocument:([1-9][0-9]*)\z/', $reference, $match) !== 1) {
                        continue 2;
                    }
                    $sourceId = (int) $match[1];
                    $document = $documents->get($sourceId);
                    if ($document === null || $document->status === 'ignored'
                        || ! hash_equals(DocumentSourceVersion::fromDocument($document), $fact->sourceVersion)
                        || ($documentId !== null && $documentId !== $sourceId)) {
                        continue 2;
                    }
                    $documentId = $sourceId;
                    $documentName = (string) $document->filename;
                    $locators[] = ['source_type' => 'document', 'source_version' => $fact->sourceVersion,
                        'document_id' => $sourceId, 'page' => $proof->page, 'region' => $proof->region,
                        'native_reference' => $proof->nativeReference];
                }
                if ($documentName === null) {
                    continue;
                }
                $rows[] = ['fact_id' => $fact->id, 'source_version' => $fact->sourceVersion, 'verification_status' => $fact->status,
                    'excerpt' => is_string($fact->value) ? mb_substr($fact->value, 0, 500) : null,
                    'document_name' => $documentName, 'locators' => $locators,
                    'can_confirm' => $fact->status === 'confirmed'];
            }
            usort($rows, static fn (array $left, array $right): int => strcmp($left['fact_id'], $right['fact_id']));
            $lastPage = max(1, (int) ceil(count($rows) / 25));
            $page = min($page, $lastPage);

            return ['entity_id' => $entityId, 'section' => $section, 'requirement' => $requirement,
                'state_version' => (int) $session->state_version, 'cost_units' => '0',
                'allowed_dispositions' => $dispositions,
                'sources' => array_slice($rows, ($page - 1) * 25, 25),
                'pagination' => ['current_page' => $page, 'last_page' => $lastPage, 'per_page' => 25, 'total' => count($rows)]];
        }, 3);
    }
}
