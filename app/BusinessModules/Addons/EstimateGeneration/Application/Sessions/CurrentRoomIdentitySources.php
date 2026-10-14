<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\DirectQuantityBinding;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\ManualRoomIdentityFactEligibility;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\RoomIdentityFactEligibility;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Validation\ValidationException;

final readonly class CurrentRoomIdentitySources
{
    public function __construct(private ProjectModelRepository $models, private EvidenceRepository $evidence,
        private RoomIdentityFactEligibility $eligibility) {}

    /** Caller holds the session lock and authorizes the actor in the same transaction. */
    public function rows(EstimateGenerationSession $session, bool $includeManual = false): array
    {
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $snapshot = $this->models->snapshot(...[...$scope, 10001]);
        if (count($snapshot->facts) > 10000) {
            throw ValidationException::withMessages(['source' => trans_message('estimate_generation.room_identity.fact_limit')]);
        }
        $entities = array_column($snapshot->entities, null, 'id');
        $facts = array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->type === 'room_name'
            || ($includeManual && $fact->type === 'entity_identity')));
        $manualIds = array_column(array_filter($facts, static fn ($fact): bool => $fact->type === 'entity_identity'), 'id');
        $decisions = $manualIds === [] ? [] : array_column($this->models->decisionsForSelectedFacts(...[...$scope, $manualIds]), null, 'selectedFactId');
        $proofs = array_column($snapshot->evidence, null, 'id');
        $ids = $documentIds = [];
        foreach ($facts as $fact) {
            foreach ($fact->evidenceIds as $id) {
                $proof = $proofs[$id] ?? null;
                if ($proof !== null && preg_match('/\Adocument:([1-9][0-9]*)\z/', $proof->sourceArtifactId, $match) === 1) {
                    $documentIds[] = (int) $match[1];
                }
                if (preg_match('/\Aevidence:([1-9][0-9]*)\z/', $id, $match) === 1) {
                    $ids[] = (int) $match[1];
                }
            }
        }
        if (count($ids) > 50000) {
            throw ValidationException::withMessages(['source' => trans_message('estimate_generation.room_identity.fact_limit')]);
        }
        $documents = [];
        foreach (array_chunk(array_values(array_unique($documentIds)), 1000) as $chunk) {
            foreach (EstimateGenerationDocument::query()->where('organization_id', $scope[0])->where('project_id', $scope[1])
                ->where('session_id', $scope[2])->whereIn('id', $chunk)->whereNull('ignored_at')->where('status', '<>', 'ignored')->orderBy('id')->sharedLock()->get() as $document) {
                $documents[(int) $document->id] = $document;
            }
        }
        $nodes = [];
        foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
            foreach ($this->evidence->activeNodesForUpdate(...[...$scope, $chunk]) as $node) {
                $nodes[$node->id] = $node;
            }
        }
        $rows = [];
        foreach ($facts as $fact) {
            $entity = $entities[$fact->entityId] ?? null;
            if ($entity !== null && $fact->type === 'entity_identity') {
                $node = (new ManualRoomIdentityFactEligibility)->proof($snapshot, $fact, $entity, $decisions[$fact->id] ?? null, $nodes);
                $proof = $node === null ? null : ($proofs['evidence:'.$node->id] ?? null);
                if ($proof !== null) {
                    $rows[] = ['source_kind' => 'user_input', 'source_fact_id' => $fact->id, 'source_fact_version' => $fact->version,
                        'source_value_hash' => DirectQuantityBinding::valueHash($fact->value), 'source_version' => $fact->sourceVersion,
                        'entity_id' => $fact->entityId, 'name' => $fact->value['name'], 'status' => 'confirmed',
                        'identity_only' => true, 'dimensions_confirmed' => false,
                        'floor' => $entity->attributes['floor_id'] ?? null, 'zone' => $entity->attributes['zone_id'] ?? null,
                        'evidence' => ['id' => $proof->id, 'source_artifact_id' => $proof->sourceArtifactId, 'document_id' => null,
                            'document_name' => null, 'page' => null, 'region' => null, 'native_reference' => null, 'source_reference' => $proof->sourceReference]];
                }

                continue;
            }
            $node = $entity === null ? null : $this->eligibility->proof($fact, $entity, $nodes);
            $document = $node === null ? null : ($documents[$node->locator['document_id']] ?? null);
            if ($node === null || $document === null || preg_match('/\A[a-f0-9]{64}\z/i', (string) $document->checksum_sha256) !== 1
                || ! hash_equals($fact->sourceVersion, DocumentSourceVersion::fromDocument($document))) {
                continue;
            }
            $proof = $proofs['evidence:'.$node->id] ?? null;
            if ($proof === null) {
                continue;
            }
            $rows[] = ['source_kind' => 'document', 'source_fact_id' => $fact->id, 'source_fact_version' => $fact->version,
                'source_value_hash' => DirectQuantityBinding::valueHash($fact->value), 'source_version' => $fact->sourceVersion,
                'entity_id' => $fact->entityId, 'name' => $fact->value, 'status' => $fact->status,
                'identity_only' => true, 'dimensions_confirmed' => false,
                'floor' => $entity->attributes['floor_id'] ?? null, 'zone' => $entity->attributes['zone_id'] ?? null,
                'evidence' => ['id' => $proof->id, 'source_artifact_id' => $proof->sourceArtifactId,
                    'document_id' => (int) $document->id, 'document_name' => $document->filename, 'page' => $proof->page,
                    'region' => $proof->region, 'native_reference' => $proof->nativeReference, 'source_reference' => $proof->sourceReference]];
        }
        usort($rows, static fn (array $left, array $right): int => [$left['floor'], $left['zone'], $left['name'], $left['entity_id']]
            <=> [$right['floor'], $right['zone'], $right['name'], $right['entity_id']]);

        return $rows;
    }
}
