<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Features\Budgeting\DTOs\BudgetImportValidationContext;
use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\BudgetImportBatch;
use App\BusinessModules\Features\Budgeting\Models\BudgetImportRow;
use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class BudgetImportService
{
    public function __construct(
        private readonly BudgetVersionService $versionService,
        private readonly BudgetLineService $lineService,
        private readonly BudgetImportFileReader $fileReader,
        private readonly BudgetImportValidator $validator
    ) {
    }

    public function preview(User $user, string $versionUuid, UploadedFile $file, array $input): BudgetImportBatch
    {
        $version = $this->versionService->findVersion($user, $versionUuid);
        $this->lineService->assertEditable($version);
        $parsed = $this->fileReader->readUploaded($file);
        $context = $this->validationContext($version, (string) ($input['mapping_mode'] ?? 'by_code'), $parsed['rows']);
        $preview = $this->validator->validate($parsed['rows'], $context);

        return DB::transaction(function () use ($version, $user, $parsed, $input, $preview): BudgetImportBatch {
            $batch = BudgetImportBatch::create([
                'organization_id' => $version->organization_id,
                'budget_version_id' => $version->id,
                'source_format' => $parsed['format'],
                'template_code' => $input['template_code'] ?? null,
                'mapping_mode' => $input['mapping_mode'] ?? 'by_code',
                'status' => ($preview->summary['rows_invalid'] ?? 0) > 0 ? 'preview_failed' : 'previewed',
                'uploaded_by' => $user->id,
                'preview_summary' => $preview->summary,
                'error_summary' => $this->errorSummary($preview->rows),
            ]);

            foreach ($preview->rows as $row) {
                BudgetImportRow::create([
                    'budget_import_batch_id' => $batch->id,
                    'row_number' => (int) $row['row_number'],
                    'raw_payload' => $row['raw_payload'],
                    'normalized_payload' => $row['normalized_payload'],
                    'validation_status' => $row['validation_status'],
                    'validation_errors' => $row['validation_errors'],
                    'validation_warnings' => $row['validation_warnings'],
                ]);
            }

            return $batch->load('rows');
        });
    }

    public function commit(User $user, string $versionUuid, string $batchUuid, string $mode): BudgetImportBatch
    {
        $version = $this->versionService->findVersion($user, $versionUuid);
        $this->lineService->assertEditable($version);

        $batch = BudgetImportBatch::query()
            ->where('organization_id', $version->organization_id)
            ->where('budget_version_id', $version->id)
            ->where('uuid', $batchUuid)
            ->with('rows')
            ->first();

        if (!$batch instanceof BudgetImportBatch) {
            throw new \DomainException(trans_message('budgeting.import.batch_not_found'));
        }

        if ($batch->status === 'committed') {
            throw new \DomainException(trans_message('budgeting.import.already_committed'));
        }

        if (($batch->preview_summary['rows_invalid'] ?? 0) > 0) {
            throw new \DomainException(trans_message('budgeting.import.commit_has_errors'));
        }

        $rows = $batch->rows
            ->filter(fn (BudgetImportRow $row): bool => in_array($row->validation_status, ['valid', 'warning'], true))
            ->map(fn (BudgetImportRow $row): array => $row->normalized_payload ?? [])
            ->filter(fn (array $row): bool => $row !== [])
            ->values()
            ->all();

        DB::transaction(function () use ($version, $batch, $rows, $mode, $user): void {
            $this->lineService->writeNormalizedRows($version, $rows, $mode);
            $batch->status = 'committed';
            $batch->committed_at = now();
            $batch->committed_by = $user->id;
            $batch->save();
        });

        return $batch->refresh()->load('rows');
    }

    public function batchToArray(BudgetImportBatch $batch): array
    {
        $batch->loadMissing(['version', 'rows']);

        return [
            'id' => $batch->uuid,
            'budget_version_id' => $batch->version?->uuid,
            'source_format' => $batch->source_format,
            'template_code' => $batch->template_code,
            'mapping_mode' => $batch->mapping_mode,
            'status' => $batch->status,
            'summary' => $batch->preview_summary,
            'error_summary' => $batch->error_summary,
            'rows' => $batch->rows->map(fn (BudgetImportRow $row): array => [
                'row_number' => $row->row_number,
                'source' => $this->safeImportRowSource($row->raw_payload ?? []),
                'normalized' => $row->normalized_payload,
                'status' => $row->validation_status,
                'errors' => $row->validation_errors ?? [],
                'warnings' => $row->validation_warnings ?? [],
            ])->values()->all(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rawRows
     */
    private function validationContext(BudgetVersion $version, string $mappingMode, array $rawRows = []): BudgetImportValidationContext
    {
        $articles = BudgetArticle::query()
            ->where('organization_id', $version->organization_id)
            ->get()
            ->map(fn (BudgetArticle $article): array => [
                'id' => (int) $article->id,
                'uuid' => (string) $article->uuid,
                'code' => (string) $article->code,
                'name' => (string) $article->name,
                'budget_kind' => (string) $article->budget_kind,
                'is_leaf' => (bool) $article->is_leaf,
                'is_active' => (bool) $article->is_active,
            ])
            ->all();

        $centers = ResponsibilityCenter::query()
            ->where('organization_id', $version->organization_id)
            ->get()
            ->map(fn (ResponsibilityCenter $center): array => [
                'id' => (int) $center->id,
                'uuid' => (string) $center->uuid,
                'code' => (string) $center->code,
                'name' => (string) $center->name,
                'is_active' => (bool) $center->is_active,
                'active_from' => $center->active_from?->toDateString(),
                'active_to' => $center->active_to?->toDateString(),
            ])
            ->all();

        return new BudgetImportValidationContext(
            organizationId: (int) $version->organization_id,
            budgetKind: (string) $version->budget_kind,
            versionUuid: (string) $version->uuid,
            versionStatus: (string) $version->status,
            periodStatus: (string) $version->period?->status,
            periodStart: CarbonImmutable::parse((string) $version->period?->starts_at)->startOfMonth(),
            periodEnd: CarbonImmutable::parse((string) $version->period?->ends_at)->startOfMonth(),
            scenarioCode: (string) $version->scenario?->code,
            currency: 'RUB',
            mappingMode: $mappingMode,
            articlesByCode: $this->indexBy($articles, 'code'),
            articlesByName: $this->indexBy($articles, 'name'),
            centersByCode: $this->indexBy($centers, 'code'),
            centersByName: $this->indexBy($centers, 'name'),
            projectIds: $this->scopedProjectIds($version, $rawRows),
            contractIds: $this->scopedContractIds($version, $rawRows),
            counterpartyIds: $this->scopedCounterpartyIds($version, $rawRows),
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexBy(array $rows, string $key): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            $value = mb_strtolower(trim((string) ($row[$key] ?? '')));
            if ($value !== '') {
                $indexed[$value] = $row;
            }
        }

        return $indexed;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function errorSummary(array $rows): array
    {
        $messages = [];

        foreach ($rows as $row) {
            foreach (($row['validation_errors'] ?? []) as $error) {
                $messages[(string) $error] = ($messages[(string) $error] ?? 0) + 1;
            }
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $rawPayload
     * @return array<string, string>
     */
    private function safeImportRowSource(array $rawPayload): array
    {
        $source = [];
        $keys = [
            'article_code',
            'article_name',
            'cfo_code',
            'responsibility_center_code',
            'responsibility_center_name',
            'month',
        ];

        foreach ($keys as $key) {
            $value = $rawPayload[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $source[$key] = trim((string) $value);
            }
        }

        return $source;
    }

    /**
     * @param list<array<string, mixed>> $rawRows
     * @return array<int, true>
     */
    private function scopedProjectIds(BudgetVersion $version, array $rawRows): array
    {
        $ids = $this->integerIds($rawRows, 'project_id');
        if ($ids === []) {
            return [];
        }

        return $this->idLookup(Project::query()
            ->whereIn('id', $ids)
            ->accessibleByOrganization((int) $version->organization_id)
            ->pluck('id')
            ->all());
    }

    /**
     * @param list<array<string, mixed>> $rawRows
     * @return array<int, true>
     */
    private function scopedContractIds(BudgetVersion $version, array $rawRows): array
    {
        $ids = $this->integerIds($rawRows, 'contract_id');
        if ($ids === []) {
            return [];
        }

        return $this->idLookup(Contract::query()
            ->where('organization_id', $version->organization_id)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all());
    }

    /**
     * @param list<array<string, mixed>> $rawRows
     * @return array<int, true>
     */
    private function scopedCounterpartyIds(BudgetVersion $version, array $rawRows): array
    {
        $ids = $this->integerIds($rawRows, 'counterparty_id');
        if ($ids === []) {
            return [];
        }

        return $this->idLookup(Contractor::query()
            ->where('organization_id', $version->organization_id)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all());
    }

    /**
     * @param list<array<string, mixed>> $rawRows
     * @return list<int>
     */
    private function integerIds(array $rawRows, string $key): array
    {
        $ids = [];

        foreach ($rawRows as $row) {
            $value = $row[$key] ?? null;
            if ($value === null || trim((string) $value) === '') {
                continue;
            }

            if (filter_var($value, FILTER_VALIDATE_INT) !== false) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<int|string> $ids
     * @return array<int, true>
     */
    private function idLookup(array $ids): array
    {
        $lookup = [];

        foreach ($ids as $id) {
            $lookup[(int) $id] = true;
        }

        return $lookup;
    }
}
