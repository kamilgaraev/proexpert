<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Review;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryRelationOverlay;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\OpeningSystemScope;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\OpeningWorkItemExpander;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CurrentOpeningSystemProofGuard
{
    public function __construct(private ProjectModelRepository $models) {}

    public function assertCurrent(EstimateGenerationSession $session, array $item): void
    {
        if (! OpeningWorkItemExpander::isOpeningWork($item)) {
            return;
        }
        DB::transaction(function () use ($session, $item): void {
            $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
            $capture = $this->models->snapshotForPlanning(...[...$scope, 10001]);
            $snapshot = $capture['snapshot'];
            $facts = array_filter($snapshot->facts, static fn ($fact): bool => in_array($fact->type, ['opening_scope', 'opening_system'], true)
                || str_starts_with($fact->type, 'geometry_parent_'));
            $decisions = $this->models->decisionsForSelectedFacts(...[...$scope, array_column($facts, 'id')]);
            $documentVersions = GeometryInventory::currentDocumentVersions($snapshot);
            $snapshot = GeometryRelationOverlay::factIds($snapshot) === [] ? $snapshot
                : GeometryRelationOverlay::apply($snapshot, $decisions, $documentVersions);
            if (count($snapshot->facts) > 10000 || ! OpeningSystemScope::permits($item, OpeningSystemScope::select($snapshot, $decisions, $documentVersions))) {
                throw ValidationException::withMessages(['work_item_key' => [trans_message('estimate_opening.system_invalid')]]);
            }
        });
    }
}
