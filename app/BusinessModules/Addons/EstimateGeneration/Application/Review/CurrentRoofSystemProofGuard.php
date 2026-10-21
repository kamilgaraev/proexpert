<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Review;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\CurrentWorkEntityBindingProof;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\RoofSystemScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CurrentRoofSystemProofGuard
{
    public function __construct(private ProjectModelRepository $models, private CurrentWorkEntityBindingProof $bindings) {}

    public function assertCurrent(EstimateGenerationSession $session, array $item): void
    {
        if (! RoofSystemScope::isRoofWork($item)) {
            return;
        }
        DB::transaction(function () use ($session, $item): void {
            $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
            $snapshot = $this->models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
            $facts = array_filter($snapshot->facts, static fn ($fact): bool => $fact->type === 'roof_system');
            $decisions = $this->models->decisionsForSelectedFacts(...[...$scope, array_column($facts, 'id')]);
            $documentVersions = GeometryInventory::currentDocumentVersions($snapshot);
            if (count($snapshot->facts) > 10000 || ! is_array($item['metadata']['scope_entity_binding'] ?? null)
                || ! RoofSystemScope::permits($item, RoofSystemScope::select($snapshot, $decisions, $documentVersions))) {
                throw ValidationException::withMessages(['work_item_key' => [trans_message('estimate_roof.system_invalid')]]);
            }
            $this->bindings->evidenceIds($session, [$item], $snapshot);
        });
    }
}
