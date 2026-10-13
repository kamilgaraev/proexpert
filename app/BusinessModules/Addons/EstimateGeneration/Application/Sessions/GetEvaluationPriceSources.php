<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationWorkItemLocator;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\CataloguePriceSnapshotReader;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\CommercialPriceTargetRegion;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ConfirmedCommercialPriceConditions;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ConvertEvaluationPriceUnit;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\SupplierPriceSnapshotReader;
use App\Models\EstimatePositionCatalog;
use App\Models\User;
use App\Models\WorkType;
use Illuminate\Validation\ValidationException;

final readonly class GetEvaluationPriceSources
{
    public function __construct(private EstimateGenerationActionAuthorization $authorization, private CataloguePriceSnapshotReader $catalog,
        private ConvertEvaluationPriceUnit $units = new ConvertEvaluationPriceUnit) {}

    public function handle(User $actor, int $projectId, int $sessionId, string $workKey, string $type = 'catalog', string $search = '', int $page = 1, int $perPage = 25): array
    {
        $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
            ->where('project_id', $projectId)->whereKey($sessionId)->firstOrFail();
        $this->authorization->authorize($actor, $session, 'estimate_generation.view');
        if (! in_array($type, ['catalog', 'work_type', 'supplier'], true) || $page < 1 || $perPage < 1 || $perPage > 50 || mb_strlen($search) > 100) {
            throw ValidationException::withMessages(['source' => trans_message('estimate_evaluation.source_search_invalid')]);
        }
        $item = (new EvaluationWorkItemLocator)->find($session->draft_payload ?? [], $session->input_payload ?? [], $workKey);
        $targetRegion = (new CommercialPriceTargetRegion)->fromSession($session);
        if ($type === 'supplier') {
            return app(SupplierPriceSnapshotReader::class)->list($actor, $session, $item, $search, $page, $perPage, $targetRegion);
        }
        $query = ($type === 'catalog' ? EstimatePositionCatalog::query() : WorkType::query())
            ->where('organization_id', $session->organization_id)->where('is_active', true);
        if ($search !== '') {
            $query->where('name', 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%');
        }
        $records = $query->orderBy('name')->orderBy('id')->paginate($perPage, ['id', 'name'], 'page', $page);
        $prices = [];
        foreach ($records->items() as $record) {
            $price = $this->catalog->read((int) $session->organization_id, $type.':'.$record->id);
            if ($price !== null) {
                $converted = $this->units->convert($price, $item['unit'] ?? null);
                $prices[] = [...($converted ?? $price), 'compatible' => $converted !== null,
                    'confirmable_conditions' => (new ConfirmedCommercialPriceConditions)->required($price, $targetRegion)];
            } else {
                $prices[] = ['source_reference' => $type.':'.$record->id, 'name' => $record->name, 'unit_price' => null,
                    'source_hash' => null, 'compatible' => false, 'verified' => false, 'unavailable_reason' => 'price_or_unit_unavailable'];
            }
        }

        return ['sources' => $prices, 'state_version' => (int) $session->state_version, 'work_item_key' => $workKey, 'target_region' => $targetRegion,
            'pagination' => ['current_page' => $records->currentPage(), 'last_page' => $records->lastPage(), 'per_page' => $perPage, 'total' => $records->total()], 'cost_units' => '0'];
    }
}
