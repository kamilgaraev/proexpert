<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScopeWorkQuantity;
use App\Exceptions\BusinessLogicException;
use App\Exceptions\MaterialConsumptionReadinessException;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\File;
use App\Models\Material;
use App\Models\MaterialConsumptionFact;
use App\Models\MaterialConsumptionRate;
use App\Models\MaterialConsumptionStatement;
use App\Models\Project;
use App\Services\MaterialConsumption\MaterialConsumptionQuantity;
use App\Services\MaterialConsumption\MaterialConsumptionRateService;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

use function trans_message;

final class MaterialConsumptionStatementService
{
    public const CALCULATION_VERSION = 'm29-v1';

    public function __construct(
        private readonly MaterialConsumptionRateService $rateService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $organizationId, array $data, int $userId): MaterialConsumptionStatement
    {
        return DB::transaction(function () use ($organizationId, $data, $userId): MaterialConsumptionStatement {
            $project = $this->lockedProject($organizationId, (int) $data['project_id']);
            $contractId = isset($data['contract_id']) ? (int) $data['contract_id'] : null;
            $this->assertContract($organizationId, (int) $project->id, $contractId);
            $periodStart = (string) $data['period_start'];
            $periodEnd = (string) $data['period_end'];
            if ($periodEnd < $periodStart) {
                throw new BusinessLogicException(trans_message('material_consumption.statement_period_invalid'), 422);
            }

            $payloadHash = $this->payloadHash([
                'project_id' => (int) $project->id,
                'contract_id' => $contractId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'document_date' => $data['document_date'] ?? null,
                'foreman_name' => isset($data['foreman_name']) ? trim((string) $data['foreman_name']) : null,
            ]);
            $idempotencyKey = (string) $data['idempotency_key'];

            $existing = MaterialConsumptionStatement::query()
                ->where('organization_id', $organizationId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                    throw new BusinessLogicException(trans_message('material_consumption.statement_idempotency_conflict'), 409);
                }

                return $existing;
            }

            $snapshot = $this->buildSnapshot($project, $contractId, $periodStart, $periodEnd, $data['foreman_name'] ?? null);
            $versionNumber = $this->nextVersionNumber((int) $project->id, $contractId, $periodStart, $periodEnd);
            $documentDate = isset($data['document_date']) ? (string) $data['document_date'] : Carbon::now()->toDateString();

            try {
                return MaterialConsumptionStatement::query()->create([
                    'organization_id' => $organizationId,
                    'project_id' => (int) $project->id,
                    'contract_id' => $contractId,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'number' => $this->documentNumber($project, $periodEnd, $versionNumber),
                    'version_number' => $versionNumber,
                    'status' => MaterialConsumptionStatement::STATUS_DRAFT,
                    'calculation_version' => self::CALCULATION_VERSION,
                    'idempotency_key' => $idempotencyKey,
                    'payload_hash' => $payloadHash,
                    'snapshot' => $snapshot,
                    'totals' => $snapshot['totals'],
                    'blockers' => $snapshot['blockers'],
                    'is_ready' => $snapshot['ready'],
                    'foreman_name' => isset($data['foreman_name']) ? trim((string) $data['foreman_name']) : null,
                    'document_date' => $documentDate,
                    'performed_at' => $periodEnd,
                    'approved_by_user_id' => null,
                    'created_by_user_id' => $userId,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new BusinessLogicException(trans_message('material_consumption.statement_identity_conflict'), 409);
            }
        });
    }

    public function approve(MaterialConsumptionStatement $statement, int $userId): MaterialConsumptionStatement
    {
        return DB::transaction(function () use ($statement, $userId): MaterialConsumptionStatement {
            $locked = MaterialConsumptionStatement::query()->lockForUpdate()->findOrFail($statement->id);
            if ($locked->status === MaterialConsumptionStatement::STATUS_APPROVED
                || $locked->status === MaterialConsumptionStatement::STATUS_SIGNED) {
                return $locked;
            }
            if ($locked->status !== MaterialConsumptionStatement::STATUS_DRAFT) {
                throw new BusinessLogicException(trans_message('material_consumption.statement_cannot_approve'), 422);
            }

            $project = Project::query()->lockForUpdate()->findOrFail($locked->project_id);
            $snapshot = $this->buildSnapshot(
                $project,
                $locked->contract_id !== null ? (int) $locked->contract_id : null,
                $locked->period_start->toDateString(),
                $locked->period_end->toDateString(),
                $locked->foreman_name,
            );
            if ($snapshot['blockers'] !== []) {
                throw new MaterialConsumptionReadinessException(
                    trans_message('material_consumption.statement_not_ready'),
                    $snapshot['blockers'],
                );
            }

            $locked->forceFill([
                'status' => MaterialConsumptionStatement::STATUS_APPROVED,
                'approved_at' => now(),
                'approved_by_user_id' => $userId,
                'snapshot' => $snapshot,
                'totals' => $snapshot['totals'],
                'blockers' => [],
                'is_ready' => true,
                'calculation_version' => self::CALCULATION_VERSION,
            ])->save();

            return $locked->refresh();
        });
    }

    public function markSigned(MaterialConsumptionStatement $statement, int $fileId, int $userId): MaterialConsumptionStatement
    {
        return DB::transaction(function () use ($statement, $fileId, $userId): MaterialConsumptionStatement {
            $locked = MaterialConsumptionStatement::query()->lockForUpdate()->findOrFail($statement->id);
            $signedFile = File::query()
                ->whereKey($fileId)
                ->where('organization_id', (int) $locked->organization_id)
                ->where('fileable_id', (int) $locked->id)
                ->where('fileable_type', MaterialConsumptionStatement::class)
                ->where('category', 'signed_m29')
                ->first();
            if ($signedFile === null) {
                throw new BusinessLogicException(trans_message('material_consumption.signed_file_invalid'), 422);
            }
            if ($locked->status === MaterialConsumptionStatement::STATUS_SIGNED) {
                if ((int) $locked->signed_file_id !== $fileId) {
                    throw new BusinessLogicException(trans_message('material_consumption.signed_file_already_registered'), 409);
                }

                return $locked->refresh();
            }
            if ($locked->status !== MaterialConsumptionStatement::STATUS_APPROVED) {
                throw new BusinessLogicException(trans_message('material_consumption.statement_must_be_approved_before_signing'), 422);
            }

            $locked->forceFill([
                'status' => MaterialConsumptionStatement::STATUS_SIGNED,
                'signed_at' => now(),
                'signed_by_user_id' => $userId,
                'signed_file_id' => $fileId,
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function exportDataset(MaterialConsumptionStatement $statement): array
    {
        if ($statement->isFrozen()) {
            return $this->datasetFromSnapshot($statement, $statement->snapshot ?? [], false);
        }

        $project = $statement->project()->firstOrFail();
        $snapshot = $this->buildSnapshot(
            $project,
            $statement->contract_id !== null ? (int) $statement->contract_id : null,
            $statement->period_start->toDateString(),
            $statement->period_end->toDateString(),
            $statement->foreman_name,
        );

        return $this->datasetFromSnapshot($statement, $snapshot, true);
    }

    /**
     * @param  array<string, mixed>  $dataset
     * @return array<string, mixed>
     */
    public function checksum(array $dataset): array
    {
        return [
            'normative' => (string) ($dataset['totals']['normative_consumption'] ?? '0'),
            'actual' => (string) ($dataset['totals']['actual_consumption'] ?? '0'),
            'deviation' => (string) ($dataset['totals']['deviation'] ?? '0'),
            'lines' => collect($dataset['section_ii'] ?? [])->map(static fn (array $line): array => [
                'key' => (string) ($line['key'] ?? ''),
                'normative' => (string) ($line['normative_consumption'] ?? '0'),
                'actual' => (string) ($line['actual_consumption'] ?? '0'),
                'deviation' => (string) ($line['deviation'] ?? '0'),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSnapshot(
        Project $project,
        ?int $contractId,
        string $periodStart,
        string $periodEnd,
        ?string $foremanName,
    ): array {
        $works = $this->worksInPeriod((int) $project->organization_id, (int) $project->id, $contractId, $periodStart, $periodEnd);
        $pairs = [];
        foreach ($works as $work) {
            $asOf = $work->completion_date?->toDateString() ?? $periodEnd;
            $rates = MaterialConsumptionRate::query()
                ->with(['material', 'materialUnit', 'workUnit', 'workType'])
                ->where('organization_id', $project->organization_id)
                ->where('work_type_id', $work->work_type_id)
                ->where('status', MaterialConsumptionRate::STATUS_APPROVED)
                ->whereDate('effective_from', '<=', $asOf)
                ->where(function ($builder) use ($asOf): void {
                    $builder->whereNull('effective_to')->orWhereDate('effective_to', '>=', $asOf);
                })
                ->get();
            foreach ($rates as $rate) {
                $key = $this->lineKey((int) $work->id, (int) $rate->material_id);
                $pairs[$key] = ['work' => $work, 'material_id' => (int) $rate->material_id];
            }
        }

        $facts = MaterialConsumptionFact::query()
            ->with(['completedWork', 'material', 'rate', 'warehouseMovement', 'qualityDocument'])
            ->where('organization_id', $project->organization_id)
            ->where('project_id', $project->id)
            ->whereIn('completed_work_id', $works->pluck('id')->all() ?: [0])
            ->orderBy('id')
            ->get();

        foreach ($facts as $fact) {
            $work = $works->firstWhere('id', $fact->completed_work_id) ?? $fact->completedWork;
            if ($work === null) {
                continue;
            }
            $key = $this->lineKey((int) $work->id, (int) $fact->material_id);
            $pairs[$key] = ['work' => $work, 'material_id' => (int) $fact->material_id];
        }

        $sectionI = [];
        $sectionII = [];
        $blockers = [];
        $normativeTotal = BigDecimal::zero();
        $actualTotal = BigDecimal::zero();

        ksort($pairs);
        foreach ($pairs as $pair) {
            /** @var CompletedWork $work */
            $work = $pair['work'];
            $materialId = $pair['material_id'];
            $line = $this->buildLine($project, $work, $materialId, $facts);
            $sectionI[] = $line['section_i'];
            $sectionII[] = $line['section_ii'];
            $normativeTotal = $normativeTotal->plus($line['normative']);
            $actualTotal = $actualTotal->plus($line['actual']);
            foreach ($line['blockers'] as $blocker) {
                $blockers[] = $blocker;
            }
        }

        $unlinkedFacts = MaterialConsumptionFact::query()
            ->where('organization_id', $project->organization_id)
            ->where('project_id', $project->id)
            ->where(function ($query): void {
                $query->whereNull('completed_work_id');
            })
            ->count();
        if ($unlinkedFacts > 0) {
            $blockers[] = [
                'code' => 'fact_unlinked',
                'message' => trans_message('material_consumption.blocker_fact_unlinked', [
                    'material' => '',
                    'work' => '',
                ]),
                'work_id' => null,
                'material_id' => null,
            ];
        }

        $deviation = $actualTotal->minus($normativeTotal);
        $organization = $project->organization()->first();

        return [
            'calculation_version' => self::CALCULATION_VERSION,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'organization' => [
                'id' => $organization?->id,
                'name' => $organization?->legal_name ?? $organization?->name,
            ],
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'contract' => $this->contractSnapshot($contractId),
            'foreman_name' => $foremanName !== null ? trim($foremanName) : null,
            'section_i' => $sectionI,
            'section_ii' => $sectionII,
            'blockers' => $blockers,
            'ready' => $blockers === [],
            'totals' => [
                'normative_consumption' => MaterialConsumptionQuantity::format($normativeTotal),
                'actual_consumption' => MaterialConsumptionQuantity::format($actualTotal),
                'deviation' => MaterialConsumptionQuantity::format($deviation),
                'overconsumption' => $deviation->isPositive() ? MaterialConsumptionQuantity::format($deviation) : '0',
                'economy' => $deviation->isNegative() ? MaterialConsumptionQuantity::format($deviation->abs()) : '0',
            ],
            'warehouse_forms' => [
                'm11' => 'Требование-накладная М-11 — отпуск со склада, не расход на объём',
                'write_off_act' => 'Акт списания — складская операция, не строка М-29',
                'm4_m7_m8_m15_m17_inv3' => 'Складские формы без изменений; реквизиты М-29 не подменяются складом',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function datasetFromSnapshot(MaterialConsumptionStatement $statement, array $snapshot, bool $isDraft): array
    {
        return [
            ...$snapshot,
            'statement' => $statement,
            'is_draft' => $isDraft,
            'number' => $statement->number,
            'document_date' => $statement->document_date?->toDateString(),
            'performed_at' => $statement->performed_at?->toDateString(),
            'status' => $statement->status,
        ];
    }

    /**
     * @param  Collection<int, MaterialConsumptionFact>  $facts
     * @return array{section_i: array<string, mixed>, section_ii: array<string, mixed>, blockers: list<array<string, mixed>>, normative: BigDecimal, actual: BigDecimal}
     */
    private function buildLine(Project $project, CompletedWork $work, int $materialId, Collection $facts): array
    {
        $asOf = $work->completion_date?->toDateString() ?? $work->created_at?->toDateString() ?? Carbon::now()->toDateString();
        $rate = $this->rateService->findApprovedForWork(
            (int) $project->organization_id,
            $materialId,
            (int) $work->work_type_id,
            $work->estimate_item_id !== null ? (int) $work->estimate_item_id : null,
            $asOf,
        );
        $rate?->loadMissing(['material.measurementUnit', 'materialUnit', 'workUnit', 'workType']);
        $material = $rate?->material ?? Material::query()->with('measurementUnit')->find($materialId);
        $workFacts = $facts->filter(
            static fn (MaterialConsumptionFact $fact): bool => (int) $fact->completed_work_id === (int) $work->id
                && (int) $fact->material_id === $materialId
        );
        $actual = BigDecimal::zero();
        $issued = BigDecimal::zero();
        $hasIssued = false;
        $reason = null;
        $agreedBy = null;
        $agreedAt = null;
        $factIds = [];
        $qualityIds = [];
        $batches = [];
        $movementIds = [];
        foreach ($workFacts as $fact) {
            $quantity = BigDecimal::of((string) $fact->converted_quantity);
            $actual = $fact->isReturn() ? $actual->minus($quantity) : $actual->plus($quantity);
            $factIds[] = (int) $fact->id;
            if ($fact->deviation_reason) {
                $reason = (string) $fact->deviation_reason;
            }
            if ($fact->agreed_by_user_id) {
                $agreedBy = (int) $fact->agreed_by_user_id;
                $agreedAt = optional($fact->agreed_at)?->toIso8601String();
            }
            if ($fact->quality_document_id) {
                $qualityIds[] = (int) $fact->quality_document_id;
            }
            if ($fact->batch_number) {
                $batches[] = (string) $fact->batch_number;
            }
            if ($fact->warehouse_movement_id) {
                $movementIds[] = (int) $fact->warehouse_movement_id;
            }
        }
        if ($movementIds !== []) {
            $hasIssued = true;
            foreach (WarehouseMovement::query()->whereKey(array_values(array_unique($movementIds)))->get() as $movement) {
                $quantity = BigDecimal::of((string) $movement->quantity);
                $issued = $movement->movement_type === WarehouseMovement::TYPE_RETURN
                    ? $issued->minus($quantity)
                    : $issued->plus($quantity);
            }
        }

        $volume = $this->acceptedVolume($work);
        $ratePerUnit = $rate !== null ? BigDecimal::of((string) $rate->quantity_per_work_unit) : BigDecimal::zero();
        $normative = MaterialConsumptionQuantity::scale($ratePerUnit->multipliedBy($volume));
        $actual = MaterialConsumptionQuantity::scale($actual);
        $deviation = $actual->minus($normative);
        $blockers = [];
        $workName = $work->workType?->name ?? $work->description ?? ('Работа '.$work->id);
        $materialName = $material?->name ?? ('Материал '.$materialId);
        if ($rate === null) {
            $blockers[] = [
                'code' => 'rate_missing',
                'message' => trans_message('material_consumption.blocker_rate_missing', [
                    'material' => $materialName,
                    'work' => $workName,
                ]),
                'work_id' => (int) $work->id,
                'material_id' => $materialId,
            ];
        }

        $key = $this->lineKey((int) $work->id, $materialId);
        $unitName = $rate?->materialUnit?->short_name ?? $material?->measurementUnit?->short_name ?? '';
        $workUnitName = $rate?->workUnit?->short_name ?? $work->workType?->measurementUnit?->short_name ?? '';
        $remainder = $hasIssued ? MaterialConsumptionQuantity::format($issued->minus($actual)) : null;

        $sectionI = [
            'key' => $key,
            'work_id' => (int) $work->id,
            'work_name' => $workName,
            'work_unit' => $workUnitName,
            'accepted_volume' => MaterialConsumptionQuantity::format($volume),
            'volume_source' => $this->volumeSource($work),
            'material_id' => $materialId,
            'material_name' => $materialName,
            'material_unit' => $unitName,
            'rate_per_unit' => $rate !== null ? MaterialConsumptionQuantity::format($ratePerUnit) : null,
            'rate_id' => $rate?->id,
            'rate_version' => $rate?->version_number,
            'rate_basis_kind' => $rate?->basis_kind,
            'rate_basis_text' => $rate?->basis_text,
            'rate_basis_document_id' => $rate?->basis_document_id,
            'normative_need' => MaterialConsumptionQuantity::format($normative),
        ];
        $sectionII = [
            'key' => $key,
            'work_id' => (int) $work->id,
            'work_name' => $workName,
            'material_id' => $materialId,
            'material_name' => $materialName,
            'material_unit' => $unitName,
            'normative_consumption' => MaterialConsumptionQuantity::format($normative),
            'actual_consumption' => MaterialConsumptionQuantity::format($actual),
            'warehouse_issued' => $hasIssued ? MaterialConsumptionQuantity::format($issued) : null,
            'site_remainder' => $remainder,
            'economy' => $deviation->isNegative() ? MaterialConsumptionQuantity::format($deviation->abs()) : '0',
            'overconsumption' => $deviation->isPositive() ? MaterialConsumptionQuantity::format($deviation) : '0',
            'deviation' => MaterialConsumptionQuantity::format($deviation),
            'deviation_reason' => $reason,
            'agreed_by_user_id' => $agreedBy,
            'agreed_at' => $agreedAt,
            'rate_basis_kind' => $rate?->basis_kind,
            'rate_basis_text' => $rate?->basis_text,
            'fact_ids' => $factIds,
            'quality_document_ids' => array_values(array_unique($qualityIds)),
            'batch_numbers' => array_values(array_unique($batches)),
            'warehouse_movement_ids' => array_values(array_unique($movementIds)),
        ];

        return [
            'section_i' => $sectionI,
            'section_ii' => $sectionII,
            'blockers' => $blockers,
            'normative' => $normative,
            'actual' => $actual,
        ];
    }

    private function acceptedVolume(CompletedWork $work): BigDecimal
    {
        $accepted = AcceptanceScopeWorkQuantity::query()
            ->where('organization_id', $work->organization_id)
            ->where('completed_work_id', $work->id)
            ->selectRaw('COALESCE(SUM(accepted_quantity), 0) as qty')
            ->value('qty');
        $acceptedDecimal = BigDecimal::of((string) ($accepted ?? '0'));
        if ($acceptedDecimal->isPositive()) {
            return MaterialConsumptionQuantity::scale($acceptedDecimal);
        }

        return MaterialConsumptionQuantity::scale(BigDecimal::of((string) $work->effectiveCompletedQuantity()));
    }

    private function volumeSource(CompletedWork $work): string
    {
        $hasAcceptance = AcceptanceScopeWorkQuantity::query()
            ->where('organization_id', $work->organization_id)
            ->where('completed_work_id', $work->id)
            ->where('accepted_quantity', '>', 0)
            ->exists();

        return $hasAcceptance ? 'technical_acceptance' : 'completed_work';
    }

    /**
     * @return Collection<int, CompletedWork>
     */
    private function worksInPeriod(
        int $organizationId,
        int $projectId,
        ?int $contractId,
        string $periodStart,
        string $periodEnd,
    ): Collection {
        return CompletedWork::query()
            ->with(['workType.measurementUnit'])
            ->physicalFacts()
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->when($contractId !== null, static fn ($query) => $query->where('contract_id', $contractId))
            ->where('status', CompletedWork::STATUS_CONFIRMED)
            ->whereDate('completion_date', '>=', $periodStart)
            ->whereDate('completion_date', '<=', $periodEnd)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function lockedProject(int $organizationId, int $projectId): Project
    {
        $project = Project::query()
            ->whereKey($projectId)
            ->where('organization_id', $organizationId)
            ->lockForUpdate()
            ->first();
        if ($project === null) {
            throw new BusinessLogicException(trans_message('material_consumption.statement_project_invalid'), 422);
        }

        return $project;
    }

    private function assertContract(int $organizationId, int $projectId, ?int $contractId): void
    {
        if ($contractId === null) {
            return;
        }
        $exists = Contract::query()
            ->whereKey($contractId)
            ->where('organization_id', $organizationId)
            ->where(function ($query) use ($projectId): void {
                $query->where('project_id', $projectId)->orWhereNull('project_id');
            })
            ->exists();
        if (! $exists) {
            throw new BusinessLogicException(trans_message('material_consumption.statement_contract_invalid'), 422);
        }
    }

    /**
     * @return array{id: int, number: string|null}|null
     */
    private function contractSnapshot(?int $contractId): ?array
    {
        if ($contractId === null) {
            return null;
        }
        $contract = Contract::query()->find($contractId);
        if ($contract === null) {
            return null;
        }

        return ['id' => (int) $contract->id, 'number' => $contract->number];
    }

    private function nextVersionNumber(int $projectId, ?int $contractId, string $periodStart, string $periodEnd): int
    {
        $query = MaterialConsumptionStatement::query()
            ->where('project_id', $projectId)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd);
        if ($contractId === null) {
            $query->whereNull('contract_id');
        } else {
            $query->where('contract_id', $contractId);
        }

        return (int) $query->max('version_number') + 1;
    }

    private function documentNumber(Project $project, string $periodEnd, int $versionNumber): string
    {
        return 'М-29-'.$project->id.'-'.substr($periodEnd, 0, 7).'-v'.$versionNumber;
    }

    private function lineKey(int $workId, int $materialId): string
    {
        return 'work:'.$workId.':material:'.$materialId;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadHash(array $payload): string
    {
        ksort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
