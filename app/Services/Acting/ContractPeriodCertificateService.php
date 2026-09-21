<?php

declare(strict_types=1);

namespace App\Services\Acting;

use App\Exceptions\BusinessLogicException;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\ContractPeriodCertificate;
use App\Models\ContractPeriodCertificateAct;
use App\Models\File;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

use function trans_message;

final class ContractPeriodCertificateService
{
    public const CALCULATION_VERSION = 'ks3-v1';

    /**
     * @param  list<int>|null  $actIds
     */
    public function create(
        Contract $contract,
        string $periodStart,
        string $periodEnd,
        ?int $projectId,
        string $idempotencyKey,
        ?int $userId = null,
        ?array $actIds = null,
        ?string $documentDate = null,
    ): ContractPeriodCertificate {
        return DB::transaction(function () use ($contract, $periodStart, $periodEnd, $projectId, $idempotencyKey, $userId, $actIds, $documentDate): ContractPeriodCertificate {
            $contract = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            $this->assertProjectBelongsToContract($contract, $projectId);

            $acts = $this->actsForPeriod($contract, $periodStart, $periodEnd, $projectId, $actIds);
            $snapshot = $this->buildSnapshot($contract, $acts, $periodStart, $periodEnd, $projectId);
            $versionNumber = $this->nextVersionNumber($contract->id, $projectId, $periodStart, $periodEnd);
            $payloadHash = $this->payloadHash(
                $contract->id,
                $projectId,
                $periodStart,
                $periodEnd,
                $acts->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all(),
            );

            $existing = ContractPeriodCertificate::query()
                ->where('contract_id', $contract->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                    throw new BusinessLogicException(trans_message('act_reports.certificate_idempotency_conflict'), 409);
                }

                return $existing->load(['memberships']);
            }

            $this->assertActsAvailable($acts->pluck('id')->all());
            $this->assertNoOpenDraft($contract->id, $projectId, $periodStart, $periodEnd);

            $performedAt = $periodEnd;
            $composedAt = $documentDate ?? Carbon::now()->toDateString();
            $number = $this->documentNumber($contract, $periodEnd, $versionNumber);

            try {
                $certificate = ContractPeriodCertificate::query()->create([
                    'organization_id' => $contract->organization_id,
                    'contract_id' => $contract->id,
                    'project_id' => $projectId,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'number' => $number,
                    'version_number' => $versionNumber,
                    'status' => ContractPeriodCertificate::STATUS_DRAFT,
                    'calculation_version' => self::CALCULATION_VERSION,
                    'idempotency_key' => $idempotencyKey,
                    'payload_hash' => $payloadHash,
                    'source_act_ids' => $acts->pluck('id')->values()->all(),
                    'composition' => $snapshot['composition'],
                    'snapshot' => $snapshot,
                    'totals' => $snapshot['totals'],
                    'document_date' => $composedAt,
                    'performed_at' => $performedAt,
                    'approved_by_user_id' => null,
                    'created_by_user_id' => $userId,
                    'has_annulled_acts' => false,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new BusinessLogicException(trans_message('act_reports.certificate_identity_conflict'), 409);
            }

            $this->syncMemberships($certificate, $snapshot['composition'], false);

            return $certificate->load(['memberships']);
        });
    }

    public function approve(ContractPeriodCertificate $certificate, int $userId): ContractPeriodCertificate
    {
        return DB::transaction(function () use ($certificate, $userId): ContractPeriodCertificate {
            $locked = ContractPeriodCertificate::query()->lockForUpdate()->findOrFail($certificate->id);
            if ($locked->status === ContractPeriodCertificate::STATUS_APPROVED || $locked->status === ContractPeriodCertificate::STATUS_SIGNED) {
                return $locked->load(['memberships']);
            }
            if ($locked->status !== ContractPeriodCertificate::STATUS_DRAFT) {
                throw new BusinessLogicException(trans_message('act_reports.certificate_cannot_approve'), 422);
            }

            Contract::query()->lockForUpdate()->findOrFail($locked->contract_id);
            $acts = $this->actsByIds((int) $locked->contract_id, $locked->source_act_ids ?? []);
            $this->assertActsStillApproved($acts, $locked->source_act_ids ?? []);
            $this->assertActsAvailable($acts->pluck('id')->all(), (int) $locked->id);
            $snapshot = $this->buildSnapshot(
                $locked->contract()->firstOrFail(),
                $acts,
                $locked->period_start->toDateString(),
                $locked->period_end->toDateString(),
                $locked->project_id !== null ? (int) $locked->project_id : null,
            );

            $locked->forceFill([
                'status' => ContractPeriodCertificate::STATUS_APPROVED,
                'approved_at' => now(),
                'approved_by_user_id' => $userId,
                'composition' => $snapshot['composition'],
                'snapshot' => $snapshot,
                'totals' => $snapshot['totals'],
                'calculation_version' => self::CALCULATION_VERSION,
            ])->save();
            $this->syncMemberships($locked, $snapshot['composition'], true);

            return $locked->refresh()->load(['memberships']);
        });
    }

    public function markSigned(ContractPeriodCertificate $certificate, int $fileId, int $userId): ContractPeriodCertificate
    {
        return DB::transaction(function () use ($certificate, $fileId, $userId): ContractPeriodCertificate {
            $locked = ContractPeriodCertificate::query()->lockForUpdate()->findOrFail($certificate->id);
            $signedFile = File::query()
                ->whereKey($fileId)
                ->where('organization_id', (int) $locked->organization_id)
                ->where('fileable_id', (int) $locked->id)
                ->where('fileable_type', ContractPeriodCertificate::class)
                ->where('category', 'signed_certificate')
                ->first();
            if ($signedFile === null) {
                throw new BusinessLogicException(trans_message('act_reports.signed_file_invalid'), 422);
            }
            if ($locked->status === ContractPeriodCertificate::STATUS_SIGNED) {
                if ((int) $locked->signed_file_id !== $fileId) {
                    throw new BusinessLogicException(trans_message('act_reports.signed_file_already_registered'), 409);
                }

                return $locked->refresh()->load(['memberships']);
            }
            if ($locked->status !== ContractPeriodCertificate::STATUS_APPROVED) {
                throw new BusinessLogicException(trans_message('act_reports.certificate_must_be_approved_before_signing'), 422);
            }

            $locked->forceFill([
                'status' => ContractPeriodCertificate::STATUS_SIGNED,
                'signed_at' => now(),
                'signed_by_user_id' => $userId,
                'signed_file_id' => $fileId,
            ])->save();

            return $locked->refresh()->load(['memberships']);
        });
    }

    public function markActAnnulled(ContractPerformanceAct $act): void
    {
        ContractPeriodCertificate::query()
            ->whereIn('status', [ContractPeriodCertificate::STATUS_APPROVED, ContractPeriodCertificate::STATUS_SIGNED])
            ->whereHas('memberships', static function ($query) use ($act): void {
                $query->where('act_id', $act->id);
            })
            ->update(['has_annulled_acts' => true]);
    }

    public function findFrozenForAct(ContractPerformanceAct $act): ?ContractPeriodCertificate
    {
        return ContractPeriodCertificate::query()
            ->whereIn('status', [ContractPeriodCertificate::STATUS_APPROVED, ContractPeriodCertificate::STATUS_SIGNED])
            ->whereHas('memberships', static function ($query) use ($act): void {
                $query->where('act_id', $act->id);
            })
            ->orderByDesc('id')
            ->first();
    }

    public function exportDataset(ContractPeriodCertificate $certificate): array
    {
        if ($certificate->isFrozen()) {
            return $this->datasetFromSnapshot($certificate, false);
        }

        $contract = $certificate->contract()->firstOrFail();
        $acts = $this->actsByIds((int) $certificate->contract_id, $certificate->source_act_ids ?? []);
        $snapshot = $this->buildSnapshot(
            $contract,
            $acts,
            $certificate->period_start->toDateString(),
            $certificate->period_end->toDateString(),
            $certificate->project_id !== null ? (int) $certificate->project_id : null,
        );

        return $this->datasetFromSnapshot($certificate, true, $snapshot);
    }

    public function checksum(array $dataset): array
    {
        return [
            'from_start' => round((float) ($dataset['total_from_start'] ?? 0), 2),
            'year_total' => round((float) ($dataset['year_total'] ?? 0), 2),
            'period' => round((float) ($dataset['total_amount'] ?? 0), 2),
            'vat' => round((float) ($dataset['vat_amount'] ?? 0), 2),
            'lines' => collect($dataset['works'] ?? [])->map(static fn (array $line): array => [
                'key' => (string) ($line['key'] ?? ''),
                'from_start' => round((float) ($line['from_start'] ?? 0), 2),
                'year_total' => round((float) ($line['year_total'] ?? 0), 2),
                'period' => round((float) ($line['amount'] ?? 0), 2),
            ])->values()->all(),
        ];
    }

    public function snapshotAggregates(ContractPeriodCertificate $certificate): array
    {
        $snapshot = $certificate->snapshot ?? [];
        $lines = [];
        foreach ($snapshot['lines'] ?? [] as $line) {
            $key = (string) ($line['key'] ?? '');
            $lines[$key] = [
                'line' => $line,
                'from_start' => (float) ($line['from_start'] ?? 0),
                'year_total' => (float) ($line['year_total'] ?? 0),
            ];
        }
        $totals = $snapshot['totals'] ?? $certificate->totals ?? [];

        return [
            'lines' => $lines,
            'year_total' => (float) ($totals['year_total'] ?? $totals['gross'] ?? 0),
            'total_from_start' => (float) ($totals['from_start'] ?? $totals['gross'] ?? 0),
            'period_total' => (float) ($totals['period'] ?? $totals['gross'] ?? 0),
            'vat' => (float) ($totals['vat'] ?? 0),
        ];
    }

    private function datasetFromSnapshot(ContractPeriodCertificate $certificate, bool $isDraft, ?array $snapshot = null): array
    {
        $snapshot ??= $certificate->snapshot ?? [];
        $totals = $snapshot['totals'] ?? $certificate->totals ?? [];
        $works = collect($snapshot['lines'] ?? [])->map(static function (array $line): array {
            return [
                'key' => $line['key'] ?? '',
                'title' => $line['title'] ?? '',
                'code' => $line['code'] ?? '',
                'unit' => $line['unit'] ?? '',
                'quantity' => (float) ($line['quantity'] ?? 0),
                'amount' => (float) ($line['amount'] ?? 0),
                'from_start' => (float) ($line['from_start'] ?? 0),
                'year_total' => (float) ($line['year_total'] ?? 0),
            ];
        })->values();

        $contract = $certificate->contract()->with(['organization', 'project.organization', 'contractor', 'estimate', 'firstParty', 'secondParty'])->firstOrFail();

        return [
            'certificate' => $certificate,
            'act' => null,
            'contract' => $contract,
            'estimate' => $contract->estimate,
            'works' => $works,
            'total_amount' => (float) ($totals['period'] ?? 0),
            'vat_amount' => (float) ($totals['vat'] ?? 0),
            'year_total' => (float) ($totals['year_total'] ?? 0),
            'total_from_start' => (float) ($totals['from_start'] ?? 0),
            'remaining_amount' => (float) ($totals['remaining'] ?? 0),
            'period_start' => $certificate->period_start,
            'period_end' => $certificate->period_end,
            'document_date' => $certificate->document_date,
            'performed_at' => $certificate->performed_at,
            'document_number' => $certificate->number,
            'is_draft' => $isDraft,
            'has_annulled_acts' => (bool) $certificate->has_annulled_acts,
            'customer_org' => null,
            'contractor' => $contract->contractor,
            'project' => $contract->project,
            'composition' => $snapshot['composition'] ?? $certificate->composition ?? [],
        ];
    }

    /**
     * @param  list<int>|null  $actIds
     */
    private function actsForPeriod(Contract $contract, string $periodStart, string $periodEnd, ?int $projectId, ?array $actIds): Collection
    {
        $query = $contract->performanceActs()
            ->where('is_approved', true)
            ->whereIn('status', [ContractPerformanceAct::STATUS_APPROVED, ContractPerformanceAct::STATUS_SIGNED])
            ->whereRaw('COALESCE(period_end, act_date)::date >= ?', [$periodStart])
            ->whereRaw('COALESCE(period_end, act_date)::date <= ?', [$periodEnd])
            ->orderBy('id')
            ->lock('FOR SHARE');
        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }
        if ($actIds !== null) {
            $requested = array_values(array_unique(array_map('intval', $actIds)));
            if ($requested === []) {
                throw new BusinessLogicException(trans_message('act_reports.certificate_empty_period'), 422);
            }
            $query->whereIn('id', $requested);
            $acts = $query->with(['lines.estimateItem', 'completedWorks.workType.measurementUnit'])->get();
            if ($acts->count() !== count($requested)) {
                throw new BusinessLogicException(trans_message('act_reports.certificate_act_not_in_period'), 422);
            }

            return $acts;
        }

        $acts = $query->with(['lines.estimateItem', 'completedWorks.workType.measurementUnit'])->get();
        if ($acts->isEmpty()) {
            throw new BusinessLogicException(trans_message('act_reports.certificate_empty_period'), 422);
        }

        return $acts;
    }

    /**
     * @param  list<int|string>  $ids
     */
    private function actsByIds(int $contractId, array $ids): Collection
    {
        $normalized = array_values(array_unique(array_map('intval', $ids)));
        if ($normalized === []) {
            throw new BusinessLogicException(trans_message('act_reports.certificate_empty_period'), 422);
        }

        return ContractPerformanceAct::query()
            ->where('contract_id', $contractId)
            ->whereIn('id', $normalized)
            ->orderBy('id')
            ->lock('FOR SHARE')
            ->with(['lines.estimateItem', 'completedWorks.workType.measurementUnit'])
            ->get();
    }

    /**
     * @param  list<int|string>  $expectedIds
     */
    private function assertActsStillApproved(Collection $acts, array $expectedIds): void
    {
        $expected = array_values(array_unique(array_map('intval', $expectedIds)));
        $valid = $acts->filter(static function (ContractPerformanceAct $act): bool {
            return $act->is_approved
                && in_array($act->status, [ContractPerformanceAct::STATUS_APPROVED, ContractPerformanceAct::STATUS_SIGNED], true);
        });
        if ($valid->count() !== count($expected) || $acts->count() !== count($expected)) {
            throw new BusinessLogicException(trans_message('act_reports.certificate_act_not_in_period'), 422);
        }
    }

    /**
     * @param  list<int>  $actIds
     */
    private function assertActsAvailable(array $actIds, ?int $exceptCertificateId = null): void
    {
        $query = ContractPeriodCertificateAct::query()
            ->whereIn('act_id', $actIds)
            ->where('is_binding', true);
        if ($exceptCertificateId !== null) {
            $query->where('certificate_id', '!=', $exceptCertificateId);
        }
        $occupied = $query->pluck('act_id')->map(static fn ($id): int => (int) $id)->all();
        if ($occupied !== []) {
            throw new BusinessLogicException(trans_message('act_reports.certificate_act_already_included'), 422);
        }
    }

    private function assertNoOpenDraft(int $contractId, ?int $projectId, string $periodStart, string $periodEnd): void
    {
        $query = ContractPeriodCertificate::query()
            ->where('contract_id', $contractId)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->where('status', ContractPeriodCertificate::STATUS_DRAFT);
        if ($projectId === null) {
            $query->whereNull('project_id');
        } else {
            $query->where('project_id', $projectId);
        }
        if ($query->lockForUpdate()->exists()) {
            throw new BusinessLogicException(trans_message('act_reports.certificate_draft_exists'), 422);
        }
    }

    private function nextVersionNumber(int $contractId, ?int $projectId, string $periodStart, string $periodEnd): int
    {
        $query = ContractPeriodCertificate::query()
            ->where('contract_id', $contractId)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd);
        if ($projectId === null) {
            $query->whereNull('project_id');
        } else {
            $query->where('project_id', $projectId);
        }

        return (int) $query->max('version_number') + 1;
    }

    private function documentNumber(Contract $contract, string $periodEnd, int $versionNumber): string
    {
        $period = Carbon::parse($periodEnd)->format('Ym');

        return 'КС-3-'.($contract->number ?: $contract->id).'-'.$period.'-'.$versionNumber;
    }

    private function assertProjectBelongsToContract(Contract $contract, ?int $projectId): void
    {
        if ($projectId === null) {
            return;
        }
        if ((int) $contract->project_id === $projectId) {
            return;
        }
        if ($contract->projects()->whereKey($projectId)->exists()) {
            return;
        }

        throw new BusinessLogicException(trans_message('act_reports.certificate_project_mismatch'), 422);
    }

    /**
     * @param  list<int>  $actIds
     */
    private function payloadHash(int $contractId, ?int $projectId, string $periodStart, string $periodEnd, array $actIds): string
    {
        $payload = [
            'contract_id' => $contractId,
            'project_id' => $projectId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'act_ids' => $actIds,
            'calculation_version' => self::CALCULATION_VERSION,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  list<array<string, mixed>>  $composition
     */
    private function syncMemberships(ContractPeriodCertificate $certificate, array $composition, bool $binding): void
    {
        ContractPeriodCertificateAct::query()->where('certificate_id', $certificate->id)->delete();
        foreach ($composition as $row) {
            ContractPeriodCertificateAct::query()->create([
                'certificate_id' => $certificate->id,
                'act_id' => (int) $row['id'],
                'act_status' => (string) ($row['status'] ?? ContractPerformanceAct::STATUS_APPROVED),
                'amount' => $row['amount'],
                'vat_amount' => $row['vat_amount'],
                'vat_rate' => $row['vat_rate'],
                'amount_without_vat' => $row['amount_without_vat'],
                'execution_date' => $row['execution_date'],
                'document_date' => $row['document_date'],
                'signed_at' => $row['signed_at'],
                'is_binding' => $binding,
            ]);
        }
    }

    private function buildSnapshot(Contract $contract, Collection $periodActs, string $periodStart, string $periodEnd, ?int $projectId): array
    {
        $periodIds = $periodActs->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $historical = $this->historicalActs($contract, $periodEnd, $projectId);
        $yearStart = Carbon::parse($periodEnd)->startOfYear()->toDateString();
        $lines = [];
        $fromStart = 0.0;
        $yearTotal = 0.0;
        $periodTotal = 0.0;

        foreach ($historical as $act) {
            $reportingDate = optional($act->period_end ?? $act->act_date)->toDateString();
            $inPeriod = in_array((int) $act->id, $periodIds, true);
            $inYear = $reportingDate !== null && $reportingDate >= $yearStart;
            $actAmount = round((float) ($act->amount ?? 0), 2);
            $fromStart = round($fromStart + $actAmount, 2);
            if ($inYear) {
                $yearTotal = round($yearTotal + $actAmount, 2);
            }
            if ($inPeriod) {
                $periodTotal = round($periodTotal + $actAmount, 2);
            }

            foreach ($this->canonicalActLines($act) as $line) {
                $key = $line['key'];
                $lines[$key] ??= [
                    'key' => $key,
                    'title' => $line['title'],
                    'code' => $line['code'],
                    'unit' => $line['unit'],
                    'quantity' => 0.0,
                    'amount' => 0.0,
                    'from_start' => 0.0,
                    'year_total' => 0.0,
                ];
                $amount = (float) $line['amount'];
                $quantity = (float) $line['quantity'];
                $lines[$key]['from_start'] = round($lines[$key]['from_start'] + $amount, 2);
                if ($inYear) {
                    $lines[$key]['year_total'] = round($lines[$key]['year_total'] + $amount, 2);
                }
                if ($inPeriod) {
                    $lines[$key]['quantity'] = round($lines[$key]['quantity'] + $quantity, 4);
                    $lines[$key]['amount'] = round($lines[$key]['amount'] + $amount, 2);
                    if ($lines[$key]['title'] === '') {
                        $lines[$key]['title'] = $line['title'];
                    }
                }
            }
        }

        $composition = $periodActs->map(function (ContractPerformanceAct $act): array {
            $vat = $this->actVat($act);

            return [
                'id' => (int) $act->id,
                'number' => $act->act_document_number,
                'status' => $act->status,
                'amount' => round((float) ($act->amount ?? 0), 2),
                'vat_amount' => $vat,
                'vat_rate' => $act->vat_rate !== null ? round((float) $act->vat_rate, 2) : null,
                'amount_without_vat' => $act->amount_without_vat !== null ? round((float) $act->amount_without_vat, 2) : null,
                'execution_date' => optional($act->period_end ?? $act->act_date)->toDateString(),
                'document_date' => optional($act->act_date)->toDateString(),
                'signed_at' => optional($act->signed_at)->toIso8601String(),
            ];
        })->values()->all();

        $periodVat = round((float) $periodActs->sum(fn (ContractPerformanceAct $act): float => $this->actVat($act)), 2);
        $estimateTotal = (float) ($contract->estimate?->total_amount ?? $contract->total_amount ?? 0);

        return [
            'calculation_version' => self::CALCULATION_VERSION,
            'composition' => $composition,
            'lines' => array_values($lines),
            'totals' => [
                'period' => $periodTotal,
                'year_total' => $yearTotal,
                'from_start' => $fromStart,
                'vat' => $periodVat,
                'net' => round($periodTotal - $periodVat, 2),
                'remaining' => max(0, round($estimateTotal - $fromStart, 2)),
            ],
        ];
    }

    private function historicalActs(Contract $contract, string $periodEnd, ?int $projectId): Collection
    {
        $query = $contract->performanceActs()
            ->where('is_approved', true)
            ->whereIn('status', [ContractPerformanceAct::STATUS_APPROVED, ContractPerformanceAct::STATUS_SIGNED])
            ->whereRaw('COALESCE(period_end, act_date) <= ?', [$periodEnd])
            ->orderByRaw('COALESCE(period_end, act_date)')
            ->orderBy('id');
        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        return $query->with(['lines.estimateItem', 'completedWorks.workType.measurementUnit'])->get();
    }

    /**
     * @return list<array{key: string, title: string, code: string, unit: string, quantity: float, amount: float}>
     */
    private function canonicalActLines(ContractPerformanceAct $act): array
    {
        $act->loadMissing([
            'lines.estimateItem',
            'lines.completedWork.workType.measurementUnit',
            'completedWorks.workType.measurementUnit',
            'completedWorks.estimateItem',
        ]);

        if ($act->lines->isNotEmpty()) {
            return $act->lines->map(function ($line): array {
                $work = $line->completedWork;
                $snapshotItem = $line->basis_snapshot['estimate_item'] ?? [];

                return [
                    'key' => $line->estimate_item_id
                        ? 'estimate:'.$line->estimate_item_id
                        : ($work?->id ? 'work:'.$work->id : 'line:'.$line->id),
                    'title' => (string) ($line->title ?: ($work?->workType?->name ?? $work?->description ?? '')),
                    'code' => (string) ($snapshotItem['normative_rate_code'] ?? $snapshotItem['justification'] ?? $snapshotItem['code'] ?? $line->estimateItem?->code ?? ''),
                    'unit' => (string) ($line->unit ?? ''),
                    'quantity' => (float) $line->quantity,
                    'amount' => (float) $line->amount,
                ];
            })->all();
        }

        return $act->completedWorks->map(static function ($work): array {
            return [
                'key' => $work->estimate_item_id ? 'estimate:'.$work->estimate_item_id : 'work:'.$work->id,
                'title' => (string) ($work->workType?->name ?? $work->description ?? ''),
                'code' => (string) ($work->workType?->code ?? ''),
                'unit' => (string) ($work->workType?->measurementUnit?->short_name ?? ''),
                'quantity' => (float) ($work->pivot->included_quantity ?? $work->quantity ?? 0),
                'amount' => (float) ($work->pivot->included_amount ?? $work->total_amount ?? 0),
            ];
        })->all();
    }

    private function actVat(ContractPerformanceAct $act): float
    {
        if ($act->vat_amount !== null) {
            return round((float) $act->vat_amount, 2);
        }
        if ($act->amount !== null && $act->amount_without_vat !== null) {
            return round((float) $act->amount - (float) $act->amount_without_vat, 2);
        }
        if ($act->amount !== null && $act->vat_rate !== null && (float) $act->vat_rate >= 0) {
            $rate = (float) $act->vat_rate;

            return round((float) $act->amount * $rate / (100 + $rate), 2);
        }

        throw new BusinessLogicException(trans_message('act_reports.certificate_vat_snapshot_missing'), 422);
    }
}
