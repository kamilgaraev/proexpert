<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Credits;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Database\Connection;

final readonly class EstimatorCreditSourceInvalidation
{
    public function __construct(private Connection $database, private EstimatorCreditOperationSettlement $settlement) {}

    /** Source mutation and invalidation share the caller's aggregate transaction. */
    public function handle(EstimateGenerationSession $session, string $reason): void
    {
        if ($this->database->transactionLevel() < 1) {
            throw new \DomainException('estimator_credit_source_change_requires_transaction');
        }
        $this->settlement->abortForSession($session, $reason);
        EstimateGenerationProcessingUnit::query()->where('organization_id', $session->organization_id)
            ->where('project_id', $session->project_id)->where('session_id', $session->id)->orderBy('id')
            ->lockForUpdate()->get()->each(static function (EstimateGenerationProcessingUnit $unit): void {
                $metadata = $unit->metadata ?? [];
                if (! array_key_exists('credit_operation_id', $metadata) && ! array_key_exists('credit_manifest_hash', $metadata)) {
                    return;
                }
                unset($metadata['credit_operation_id'], $metadata['credit_manifest_hash']);
                $unit->forceFill(['metadata' => $metadata])->save();
            });
        $session->input_payload = [...($session->input_payload ?? []), 'generation_credit_operation_id' => null,
            'generation_attempt_id' => null, 'generation_requested' => false];
    }
}
