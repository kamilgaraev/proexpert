<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationWorkItemLocator;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\NormativeResourcePriceTarget;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\AcceptedResourceCataloguePrices;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\CataloguePriceSnapshotReader;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\CommercialPriceTargetRegion;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ConfirmedCommercialPriceConditions;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ConvertEvaluationPriceUnit;
use App\Models\EstimatePositionCatalog;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class EvaluationResourcePriceChoices
{
    public function __construct(private Connection $database, private EstimateGenerationActionAuthorization $authorization,
        private BuildMostEstimateDraft $artifacts, private CataloguePriceSnapshotReader $catalog,
        private AcceptedResourceCataloguePrices $accepted, private UserEvaluationChoiceWriter $writer,
        private ProjectModelRepository $models) {}

    public function resources(User $actor, int $projectId, int $sessionId, string $workKey): array
    {
        $session = $this->session($actor, $projectId, $sessionId);
        $this->authorization->authorize($actor, $session, 'estimate_generation.view');
        $item = $this->item($session, $workKey);

        $context = $this->accepted->context($session);
        $resources = [];
        foreach ((new NormativeResourcePriceTarget)->targets($item, $this->generation($session)) as $target) {
            $key = NormativeResourcePriceTarget::key($workKey, $target['norm_id'], $target['norm_resource_id']);
            $entry = $context['choices'][$key] ?? null;
            $selection = $entry['selection'] ?? null;
            $currentGeneration = is_array($selection) && ($selection['generation_id'] ?? null) === $this->generation($session);
            $current = $currentGeneration && ($selection['target_hash'] ?? null) === $target['target_hash'] && is_array($entry['price'] ?? null)
                && $this->accepted->usable($session, $target, $entry['price'], $selection) !== null;
            $resources[] = [...$target, 'selection_status' => $currentGeneration ? ($current ? 'current' : 'review_required') : 'not_selected',
                'selected_source_reference' => $currentGeneration ? ($selection['source_reference'] ?? null) : null];
        }

        return ['contract_version' => NormativeResourcePriceTarget::CONTRACT, 'state_version' => (int) $session->state_version,
            'work_item_key' => $workKey, 'resources' => $resources,
            'selection_available' => ($session->input_payload['price_policy'] ?? null) === 'mixed', 'cost_units' => '0', 'estimate_complete' => false];
    }

    public function sources(User $actor, int $projectId, int $sessionId, string $workKey, int $normResourceId,
        string $search = '', int $page = 1, int $perPage = 25): array
    {
        if ($normResourceId < 1 || $page < 1 || $perPage < 1 || $perPage > 50 || mb_strlen($search) > 100) {
            $this->reject();
        }
        $session = $this->session($actor, $projectId, $sessionId);
        $this->authorization->authorize($actor, $session, 'estimate_generation.view');
        $target = $this->target($session, $this->item($session, $workKey), $normResourceId);
        $region = (new CommercialPriceTargetRegion)->fromSession($session);
        $query = EstimatePositionCatalog::query()->where('organization_id', $session->organization_id)->where('is_active', true)->where('item_type', 'material');
        if ($search !== '') {
            $query->where('name', 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%');
        }
        $records = $query->orderBy('name')->orderBy('id')->paginate($perPage, ['id', 'name'], 'page', $page);
        $prices = $this->catalog->readMaterialPrices((int) $session->organization_id,
            array_map(static fn ($record): string => 'catalog:'.$record->id, $records->items()));
        $sources = [];
        foreach ($records->items() as $record) {
            $price = $prices['catalog:'.$record->id] ?? null;
            if ($price === null) {
                $sources[] = ['source_reference' => 'catalog:'.$record->id, 'name' => $record->name, 'available' => false];

                continue;
            }
            $required = (new ConfirmedCommercialPriceConditions)->required($price, $region);
            $sources[] = [...$price, 'unit_compatible' => (new ConvertEvaluationPriceUnit)->convert($price, $target['unit']) !== null,
                'source_conditions_available' => ($price['currency'] ?? null) === 'RUB' && ($price['delivery_included'] ?? null) === true
                    && ($price['applicable'] ?? null) === true && array_diff($price['conditions_missing'], ['region']) === [],
                'requires_product_confirmation' => true, 'confirmable_conditions' => $required];
        }

        return ['contract_version' => NormativeResourcePriceTarget::CONTRACT, 'state_version' => (int) $session->state_version,
            'work_item_key' => $workKey, 'resource' => $target, 'sources' => $sources, 'target_region' => $region,
            'pagination' => ['current_page' => $records->currentPage(), 'last_page' => $records->lastPage(), 'per_page' => $perPage, 'total' => $records->total()],
            'cost_units' => '0', 'estimate_complete' => false];
    }

    public function save(User $actor, int $projectId, int $sessionId, array $input): array
    {
        $allowed = ['request_id', 'state_version', 'work_item_key', 'norm_resource_id', 'target_hash', 'source_reference', 'source_hash',
            'same_product_confirmed', 'stale_accepted', 'conditions_confirmation', 'reason'];
        if (array_diff(array_keys($input), $allowed) !== [] || ! Str::isUuid($input['request_id'] ?? '')
            || ! is_int($input['state_version'] ?? null) || $input['state_version'] < 0
            || ! is_int($input['norm_resource_id'] ?? null) || $input['norm_resource_id'] < 1
            || ! is_string($input['work_item_key'] ?? null) || strlen($input['work_item_key']) < 1 || strlen($input['work_item_key']) > 160
            || ! is_string($input['target_hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $input['target_hash']) !== 1
            || ! is_string($input['source_hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $input['source_hash']) !== 1
            || ! is_string($input['source_reference'] ?? null) || strlen($input['source_reference']) > 100
            || preg_match('/\Acatalog:[1-9][0-9]*\z/', $input['source_reference']) !== 1
            || ($input['same_product_confirmed'] ?? null) !== true || (isset($input['stale_accepted']) && ! is_bool($input['stale_accepted']))
            || ! is_string($input['reason'] ?? null) || trim($input['reason']) === '' || strlen($input['reason']) > 1000
            || preg_match('/[\x00-\x1F\x7F]/', $input['reason']) === 1) {
            $this->reject();
        }

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $input): array {
            $session = $this->session($actor, $projectId, $sessionId, true);
            $this->authorization->authorize($actor, $session, 'estimate_generation.review');
            $payload = $session->input_payload;
            $receipts = $payload['resource_price_receipts'] ?? [];
            $fingerprint = hash('sha256', CanonicalPipelineJson::encode($input));
            if (isset($receipts[$input['request_id']])) {
                $receipt = $receipts[$input['request_id']];
                if ($receipt['actor_id'] !== (int) $actor->id || ! hash_equals($receipt['fingerprint'], $fingerprint)) {
                    $this->reject();
                }

                return $receipt['result'];
            }
            if ($session->state_version !== $input['state_version'] || ! in_array($session->status->value, ['estimate_review_required', 'ready_to_apply'], true)) {
                throw new StaleEstimateGenerationState($sessionId, $input['state_version']);
            }
            if (count($receipts) >= 10000 || ($payload['price_policy'] ?? null) !== 'mixed') {
                $this->reject();
            }
            $item = $this->item($session, $input['work_item_key']);
            $target = $this->target($session, $item, $input['norm_resource_id'], true);
            if (! hash_equals($target['target_hash'], $input['target_hash'])) {
                $this->reject();
            }
            $sourceId = (int) substr($input['source_reference'], 8);
            if (EstimatePositionCatalog::query()->where('organization_id', $session->organization_id)->where('is_active', true)->sharedLock()->find($sourceId) === null) {
                $this->reject();
            }
            $price = $this->catalog->readMaterialPrices((int) $session->organization_id, [$input['source_reference']])[$input['source_reference']] ?? null;
            if ($price === null || ! hash_equals($price['source_hash'], $input['source_hash'])) {
                $this->reject();
            }
            $confirmation = (new ConfirmedCommercialPriceConditions)->validate($price, $session, $item, $input['conditions_confirmation'] ?? null);
            if (array_diff(array_keys($confirmation), ['region']) !== []) {
                $this->reject();
            }
            $selection = ['contract_version' => NormativeResourcePriceTarget::CONTRACT, 'generation_id' => $this->generation($session),
                'work_item_key' => $item['key'], 'norm_id' => $target['norm_id'], 'norm_resource_id' => $target['norm_resource_id'],
                'target_hash' => $target['target_hash'], 'source_reference' => $input['source_reference'], 'source_hash' => $input['source_hash'],
                'same_product_confirmed' => true, 'stale_accepted' => ($input['stale_accepted'] ?? false) === true, 'conditions_confirmation' => $confirmation];
            if ($this->accepted->usable($session, $target, $price, $selection) === null) {
                $this->reject();
            }
            $key = NormativeResourcePriceTarget::key($item['key'], $target['norm_id'], $target['norm_resource_id']);
            $snapshot = $this->models->snapshot((int) $session->organization_id, (int) $session->project_id, (int) $session->id, 10001);
            $additional = isset($payload['resource_price_selections'][$key]) ? 0 : 1;
            if (count($snapshot->facts) + $additional > 10000) {
                $this->reject();
            }
            $reason = trim($input['reason']);
            $proof = $this->writer->write($actor, $session, 'resource-price:'.$key, 'evaluation_price', $selection, $reason, $input['request_id']);
            $payload['resource_price_selections'][$key] = [...$selection, 'reason' => $reason, ...$proof];
            $payload['resource_price_selection_revision'] = ($payload['resource_price_selection_revision'] ?? 0) + 1;
            unset($payload['confirmed_evaluation']);
            $result = [...$proof, 'request_id' => $input['request_id'], 'state_version' => $session->state_version + 1,
                'work_item_key' => $item['key'], 'norm_resource_id' => $target['norm_resource_id'], 'cost_units' => '0',
                'recalculation_required' => true, 'estimate_complete' => false];
            $receipts[$input['request_id']] = ['actor_id' => (int) $actor->id, 'fingerprint' => $fingerprint, 'result' => $result];
            $payload['resource_price_receipts'] = $receipts;
            $session->update(['input_payload' => $payload, 'state_version' => $result['state_version']]);

            return $result;
        }, 3);
    }

    private function session(User $actor, int $projectId, int $sessionId, bool $lock = false): EstimateGenerationSession
    {
        $query = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)->where('project_id', $projectId)->whereKey($sessionId);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function item(EstimateGenerationSession $session, string $workKey): array
    {
        if (! config('estimate-generation.universal_enabled', false) || ($session->input_payload['evaluation_mode'] ?? null) !== 'universal'
            || ! $this->artifacts->verifyArtifact($session->draft_payload ?? []) || isset($session->input_payload['price_selections'][$workKey])) {
            $this->reject();
        }

        return (new EvaluationWorkItemLocator)->find($session->draft_payload ?? [], $session->input_payload ?? [], $workKey);
    }

    private function target(EstimateGenerationSession $session, array $item, int $id, bool $lock = false): array
    {
        $reader = new NormativeResourcePriceTarget;
        try {
            $target = $reader->find($item, $this->generation($session), $id);
        } catch (InvalidArgumentException) {
            $this->reject();
        }
        $query = DB::table('estimate_norm_resources')->where('id', $id)->where('estimate_norm_id', $target['norm_id']);
        $row = ($lock ? $query->sharedLock() : $query)->first();
        if ($row === null || ! $reader->matchesCurrentRow($target, $row)) {
            $this->reject();
        }

        return $target;
    }

    private function generation(EstimateGenerationSession $session): string
    {
        return (string) ($session->input_payload['composition_generation_id'] ?? '');
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['resource_price' => trans_message('estimate_resource_price.unavailable')]);
    }
}
