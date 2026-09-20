<?php

declare(strict_types=1);

namespace App\Services\CompletedWork;

use App\Domain\Project\ValueObjects\ProjectContext;
use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\CompletedWorkCorrection;
use App\Models\PerformanceActLine;
use App\Services\Acting\ActingQuantityStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

use function trans_message;

final class CompletedWorkCorrectionService
{
    public function __construct(private readonly CompletedWorkScopeResolver $scopeResolver) {}

    public function correct(
        CompletedWork $work,
        User $actor,
        ProjectContext $context,
        array $input,
    ): CompletedWorkCorrection {
        $this->scopeResolver->assertCorrection($work, $actor, $context);

        if ($work->work_origin_type === CompletedWork::ORIGIN_JOURNAL || $work->journal_entry_id !== null) {
            throw new BusinessLogicException(trans_message('completed_work.correction_required'), 409);
        }

        $quantity = (float) $input['quantity'];
        $completedQuantity = (float) ($input['completed_quantity'] ?? $quantity);
        if (array_key_exists('completed_quantity', $input) && abs($completedQuantity - $quantity) > 0.0000001) {
            throw new BusinessLogicException(trans_message('completed_work.correction_required'), 422);
        }
        if ($quantity < 0) {
            throw new BusinessLogicException(trans_message('completed_work.correction_required'), 422);
        }

        return DB::transaction(function () use ($work, $actor, $input, $quantity, $completedQuantity): CompletedWorkCorrection {
            $lockedWork = CompletedWork::query()->whereKey($work->id)->lockForUpdate()->firstOrFail();
            $operationKey = (string) $input['operation_key'];
            $price = $lockedWork->price !== null ? (float) $lockedWork->price : null;
            if ($price === null && $lockedWork->total_amount !== null) {
                throw new BusinessLogicException(trans_message('completed_work.correction_required'), 422);
            }
            $after = [
                'quantity' => $quantity,
                'completed_quantity' => $completedQuantity,
                'price' => $price,
                'total_amount' => $price === null ? null : round($price * $quantity, 2),
                'status' => CompletedWork::STATUS_PENDING,
            ];
            $payloadHash = hash('sha256', json_encode([
                'quantity' => $quantity,
                'completed_quantity' => $completedQuantity,
                'reason' => (string) $input['reason'],
                'source_event_id' => $input['source_event_id'] ?? null,
            ], JSON_THROW_ON_ERROR));
            $existing = CompletedWorkCorrection::query()
                ->where('completed_work_id', $lockedWork->id)
                ->where('operation_key', $operationKey)
                ->first();
            if ($existing) {
                if ($existing->payload_hash !== $payloadHash) {
                    throw new BusinessLogicException(trans_message('completed_work.correction_conflict'), 409);
                }
                return $existing;
            }

            if (CompletedWorkRevisionToken::forWork($lockedWork) !== (string) $input['expected_version']) {
                throw new BusinessLogicException(trans_message('completed_work.correction_stale'), 409);
            }
            if ($lockedWork->work_origin_type === CompletedWork::ORIGIN_JOURNAL || $lockedWork->journal_entry_id !== null) {
                throw new BusinessLogicException(trans_message('completed_work.correction_required'), 409);
            }
            if ($lockedWork->performanceActs()->where(function ($query): void {
                $query->whereNull('status')->orWhereNotIn('status', ActingQuantityStatus::releasedStatuses());
            })->exists()) {
                throw new BusinessLogicException(trans_message('completed_work.correction_blocked_by_act'), 409);
            }
            if (PerformanceActLine::query()
                ->where('completed_work_id', $lockedWork->id)
                ->whereHas('performanceAct', static function ($query): void {
                    $query->where(function ($statusQuery): void {
                        $statusQuery->whereNull('status')->orWhereNotIn('status', ActingQuantityStatus::releasedStatuses());
                    });
                })->exists()) {
                throw new BusinessLogicException(trans_message('completed_work.correction_blocked_by_act'), 409);
            }
            if (! empty($input['source_event_id'])
                && ! CompletedWorkCorrection::query()->whereKey($input['source_event_id'])->where('completed_work_id', $lockedWork->id)->exists()) {
                throw new BusinessLogicException(trans_message('completed_work.correction_required'), 422);
            }

            $before = [
                'quantity' => $lockedWork->quantity,
                'completed_quantity' => $lockedWork->completed_quantity,
                'price' => $lockedWork->price,
                'total_amount' => $lockedWork->total_amount,
                'status' => $lockedWork->status,
                'additional_info' => $lockedWork->additional_info,
            ];
            $additionalInfo = $lockedWork->additional_info ?? [];
            if (is_array($additionalInfo)) {
                $baseUnitPrice = data_get($additionalInfo, 'financial_calculation.base_unit_price');
                if ($baseUnitPrice !== null) {
                    data_set($additionalInfo, 'financial_calculation.base_total_amount', round((float) $baseUnitPrice * $quantity, 2));
                    data_set($additionalInfo, 'financial_calculation.adjusted_total_amount', $after['total_amount']);
                }
                $after['additional_info'] = $additionalInfo;
            }
            $correction = CompletedWorkCorrection::query()->create([
                'completed_work_id' => $lockedWork->id,
                'organization_id' => $lockedWork->organization_id,
                'project_id' => $lockedWork->project_id,
                'actor_id' => $actor->id,
                'operation_key' => $operationKey,
                'expected_version' => (string) $input['expected_version'],
                'reason' => $input['reason'],
                'source_event_id' => $input['source_event_id'] ?? null,
                'payload_hash' => $payloadHash,
                'snapshot_before' => $before,
                'snapshot_after' => $after,
            ]);
            $lockedWork->forceFill($after)->save();

            return $correction;
        });
    }
}
