<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationWorkItemLocator;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\CataloguePriceSnapshotReader;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ConfirmedCommercialPriceConditions;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ConvertEvaluationPriceUnit;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\SupplierPriceSnapshotReader;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class SaveEvaluationPriceSelection
{
    public function __construct(private Connection $database, private EstimateGenerationActionAuthorization $authorization,
        private CataloguePriceSnapshotReader $catalog, private SupplierPriceSnapshotReader $suppliers,
        private UserEvaluationChoiceWriter $choices, private ConvertEvaluationPriceUnit $units = new ConvertEvaluationPriceUnit) {}

    public function handle(User $actor, int $projectId, int $sessionId, array $input): array
    {
        if (! Str::isUuid($input['request_id'] ?? '') || ! is_int($input['state_version'] ?? null)
            || ! is_string($input['work_item_key'] ?? null) || ! is_string($input['source_reference'] ?? null)
            || $input['state_version'] < 0 || mb_strlen($input['work_item_key']) < 1 || mb_strlen($input['work_item_key']) > 160
            || preg_match('/\A(?:(?:catalog|work_type):[1-9][0-9]*|supplier:[1-9][0-9]*:[1-9][0-9]*)\z/', $input['source_reference']) !== 1
            || strlen($input['source_reference']) > 100 || (isset($input['stale_accepted']) && ! is_bool($input['stale_accepted']))
            || ! is_string($input['source_hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $input['source_hash']) !== 1) {
            throw ValidationException::withMessages(['price' => trans_message('estimate_evaluation.price_request_invalid')]);
        }

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $input): array {
            $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($actor, $session, 'estimate_generation.review');
            if (! config('estimate-generation.universal_enabled', false) || ($session->input_payload['evaluation_mode'] ?? null) !== 'universal') {
                throw ValidationException::withMessages(['evaluation' => trans_message('estimate_evaluation.disabled')]);
            }
            $payload = $session->input_payload;
            $receipts = $payload['evaluation_price_receipts'] ?? [];
            $fingerprint = hash('sha256', CanonicalPipelineJson::encode($input));
            if (isset($receipts[$input['request_id']])) {
                $existing = $receipts[$input['request_id']];
                if ($existing['actor_id'] !== (int) $actor->id || ! hash_equals($existing['fingerprint'], $fingerprint)) {
                    throw ValidationException::withMessages(['request_id' => trans_message('estimate_evaluation.price_receipt_conflict')]);
                }

                return $existing['result'];
            }
            if ($session->state_version !== $input['state_version'] || $session->status->isTerminal()
                || in_array($session->status->value, ['applying', 'generating', 'processing_documents'], true)) {
                throw new StaleEstimateGenerationState($sessionId, $input['state_version']);
            }
            if (count($receipts) >= 10000 || ($payload['price_policy'] ?? 'normative') === 'normative') {
                throw ValidationException::withMessages(['price' => trans_message('estimate_evaluation.price_policy_or_limit')]);
            }
            $item = (new EvaluationWorkItemLocator)->find($session->draft_payload ?? [], $payload, $input['work_item_key']);
            $reference = $input['source_reference'];
            $price = str_starts_with($reference, 'supplier:')
                ? $this->suppliers->read($actor, $session, $reference, new DateTimeImmutable('today'),
                    ['material_id' => $item['material_id'] ?? null, 'quantity' => $item['quantity'] ?? null, 'unit' => $item['unit'] ?? null])
                : $this->catalog->read((int) $session->organization_id, $reference);
            if ($price === null || ($price['applicable'] ?? true) !== true || ! hash_equals($price['source_hash'], $input['source_hash'])
                || $this->units->convert($price, $item['unit'] ?? null) === null) {
                throw ValidationException::withMessages(['source_reference' => trans_message('estimate_evaluation.price_changed')]);
            }
            $staleAccepted = ($input['stale_accepted'] ?? false) === true;
            if (($price['stale'] ?? false) === true && ! $staleAccepted) {
                throw ValidationException::withMessages(['stale_accepted' => trans_message('estimate_evaluation.accept_stale_price')]);
            }
            $confirmation = (new ConfirmedCommercialPriceConditions)->validate($price, $session, $item, $input['conditions_confirmation'] ?? null);
            $selection = ['work_item_key' => $item['key'], 'source_reference' => $reference, 'source_hash' => $price['source_hash'],
                'stale_accepted' => $staleAccepted, 'conditions_confirmation' => $confirmation];
            $proof = $this->choices->write($actor, $session, 'work:'.$item['key'], 'evaluation_price', $selection,
                trans_message('estimate_evaluation.price_selected'), $input['request_id']);
            $payload['price_selections'][$item['key']] = [...$selection, ...$proof];
            $payload['price_selection_revision'] = ($payload['price_selection_revision'] ?? 0) + 1;
            unset($payload['confirmed_evaluation']);
            $result = [...$proof, 'request_id' => $input['request_id'], 'state_version' => $session->state_version + 1,
                'cost_units' => '0', 'recalculation_required' => true];
            $receipts[$input['request_id']] = ['fingerprint' => $fingerprint, 'actor_id' => (int) $actor->id, 'result' => $result];
            $payload['evaluation_price_receipts'] = $receipts;
            $session->update(['input_payload' => $payload, 'state_version' => $result['state_version']]);

            return $result;
        }, 3);
    }
}
