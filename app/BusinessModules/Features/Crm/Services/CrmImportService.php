<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Services;

use App\BusinessModules\Features\Crm\Models\CrmCompany;
use App\BusinessModules\Features\Crm\Models\CrmContact;
use App\BusinessModules\Features\Crm\Models\CrmDeal;
use App\BusinessModules\Features\Crm\Models\CrmImportBatch;
use App\BusinessModules\Features\Crm\Models\CrmImportRow;
use App\BusinessModules\Features\Crm\Models\CrmLead;
use App\Models\Organization;
use App\Services\Storage\FileService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

use function trans_message;

final class CrmImportService
{
    private const PREVIEW_ROW_LIMIT = 1000;

    public function __construct(
        private readonly FileService $fileService,
        private readonly CrmRegistryService $registry,
        private readonly CrmDuplicateService $duplicates,
        private readonly CrmTextNormalizer $normalizer
    ) {
    }

    public function preview(
        int $organizationId,
        UploadedFile $file,
        string $entityType,
        array $mapping,
        ?int $actorUserId
    ): CrmImportBatch {
        $organization = Organization::query()->findOrFail($organizationId);
        $storedPath = $this->fileService->upload(
            $file,
            'crm/imports',
            null,
            'private',
            $organization
        );

        if ($storedPath === false) {
            throw ValidationException::withMessages([
                'file' => trans_message('crm.import.errors.upload_failed'),
            ]);
        }

        [$headers, $rows] = $this->readRows($file);
        $resolvedMapping = $this->resolveMapping($entityType, $headers, $mapping);

        return DB::transaction(function () use (
            $organizationId,
            $file,
            $entityType,
            $storedPath,
            $actorUserId,
            $resolvedMapping,
            $rows
        ): CrmImportBatch {
            $batch = CrmImportBatch::query()->create([
                'organization_id' => $organizationId,
                'entity_type' => $entityType,
                'source_format' => mb_strtolower($file->getClientOriginalExtension() ?: 'csv'),
                'status' => 'previewed',
                'original_filename' => $file->getClientOriginalName(),
                'stored_path' => $storedPath,
                'total_rows' => count($rows),
                'mapping' => $resolvedMapping,
                'summary' => [],
                'uploaded_by_user_id' => $actorUserId,
                'progress_percent' => 0,
            ]);

            $accepted = 0;
            $warnings = 0;
            $blocked = 0;

            foreach ($rows as $rowNumber => $rawValues) {
                $normalized = $this->normalizeRow($entityType, $rawValues, $resolvedMapping);
                $errors = $this->validateRow($entityType, $normalized);
                $duplicateCandidates = $errors === []
                    ? $this->duplicates->hintsForRow($organizationId, $entityType, $normalized)
                    : [];
                $rowStatus = $errors === []
                    ? ($duplicateCandidates === [] ? 'accepted' : 'warning')
                    : 'blocked';

                $accepted += $rowStatus === 'accepted' ? 1 : 0;
                $warnings += $rowStatus === 'warning' ? 1 : 0;
                $blocked += $rowStatus === 'blocked' ? 1 : 0;

                CrmImportRow::query()->create([
                    'batch_id' => $batch->id,
                    'row_number' => $rowNumber,
                    'raw_values' => $rawValues,
                    'normalized_values' => $normalized,
                    'decision' => $rowStatus === 'blocked' ? 'skip' : 'create',
                    'status' => $rowStatus,
                    'validation_errors' => $errors,
                    'validation_warnings' => $duplicateCandidates === [] ? [] : [trans_message('crm.import.warnings.duplicates_found')],
                    'duplicate_candidates' => $duplicateCandidates,
                ]);
            }

            $batch->update([
                'accepted_rows' => $accepted,
                'warning_rows' => $warnings,
                'blocked_rows' => $blocked,
                'summary' => [
                    'accepted' => $accepted,
                    'warnings' => $warnings,
                    'blocked' => $blocked,
                ],
            ]);

            return $batch->fresh(['rows']);
        });
    }

    public function findBatch(int $organizationId, string $batchId): CrmImportBatch
    {
        return CrmImportBatch::query()
            ->forOrganization($organizationId)
            ->with(['rows'])
            ->findOrFail($batchId);
    }

    public function paginateRows(int $organizationId, string $batchId, int $perPage): LengthAwarePaginator
    {
        $batch = CrmImportBatch::query()
            ->forOrganization($organizationId)
            ->findOrFail($batchId);

        return CrmImportRow::query()
            ->where('batch_id', $batch->id)
            ->orderBy('row_number')
            ->paginate($perPage);
    }

    public function confirm(int $organizationId, string $batchId, array $decisions, ?int $actorUserId): CrmImportBatch
    {
        return DB::transaction(function () use ($organizationId, $batchId, $decisions, $actorUserId): CrmImportBatch {
            $batch = CrmImportBatch::query()
                ->forOrganization($organizationId)
                ->lockForUpdate()
                ->findOrFail($batchId);

            if ($batch->status !== 'previewed') {
                throw ValidationException::withMessages([
                    'batch' => trans_message('crm.import.errors.batch_already_processed'),
                ]);
            }

            $this->applyDecisions($batch, $decisions);
            $rows = CrmImportRow::query()
                ->where('batch_id', $batch->id)
                ->orderBy('row_number')
                ->get();
            $imported = 0;
            $skipped = 0;
            $blocked = 0;

            foreach ($rows as $row) {
                if ($row->status === 'blocked' || $row->decision === 'skip') {
                    $row->update(['status' => $row->status === 'blocked' ? 'blocked' : 'skipped']);
                    $blocked += $row->status === 'blocked' ? 1 : 0;
                    $skipped += $row->status !== 'blocked' ? 1 : 0;
                    continue;
                }

                $entity = $this->persistRow($organizationId, $batch->entity_type, $row, $actorUserId);
                $row->update([
                    'created_entity_id' => $entity?->id,
                    'status' => $entity === null ? 'skipped' : 'imported',
                ]);
                $imported += $entity === null ? 0 : 1;
                $skipped += $entity === null ? 1 : 0;
            }

            $batch->update([
                'status' => 'confirmed',
                'confirmed_by_user_id' => $actorUserId,
                'confirmed_at' => now(),
                'progress_percent' => 100,
                'summary' => array_merge($batch->summary ?? [], [
                    'imported' => $imported,
                    'skipped' => $skipped,
                    'blocked' => $blocked,
                ]),
            ]);

            return $batch->fresh(['rows']);
        });
    }

    public function cancel(int $organizationId, string $batchId): CrmImportBatch
    {
        $batch = CrmImportBatch::query()
            ->forOrganization($organizationId)
            ->findOrFail($batchId);

        if ($batch->status === 'confirmed') {
            throw ValidationException::withMessages([
                'batch' => trans_message('crm.import.errors.confirmed_cannot_be_cancelled'),
            ]);
        }

        $batch->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        return $batch->fresh(['rows']);
    }

    private function readRows(UploadedFile $file): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = min($sheet->getHighestRow(), self::PREVIEW_ROW_LIMIT + 1);
        $highestColumn = $sheet->getHighestColumn();
        $headers = $this->normalizeHeaders($sheet->rangeToArray("A1:{$highestColumn}1", null, true, true, true)[1] ?? []);
        $rows = [];

        for ($rowIndex = 2; $rowIndex <= $highestRow; $rowIndex++) {
            $rowValues = $sheet->rangeToArray("A{$rowIndex}:{$highestColumn}{$rowIndex}", null, true, true, true)[$rowIndex] ?? [];
            $assoc = [];
            $hasValue = false;

            foreach ($headers as $column => $header) {
                $value = $rowValues[$column] ?? null;
                $value = is_scalar($value) ? trim((string) $value) : $value;
                $assoc[$header] = $value;
                $hasValue = $hasValue || ($value !== null && $value !== '');
            }

            if ($hasValue) {
                $rows[$rowIndex] = $assoc;
            }
        }

        $spreadsheet->disconnectWorksheets();

        return [$headers, $rows];
    }

    private function normalizeHeaders(array $headerRow): array
    {
        $headers = [];

        foreach ($headerRow as $column => $value) {
            $header = trim((string) $value);
            $headers[$column] = $header !== '' ? $header : (string) $column;
        }

        return $headers;
    }

    private function resolveMapping(string $entityType, array $headers, array $providedMapping): array
    {
        if ($providedMapping !== []) {
            return $providedMapping;
        }

        $fieldAliases = $this->aliases($entityType);
        $mapping = [];
        $headerLookup = [];

        foreach ($headers as $header) {
            $headerLookup[$this->key($header)] = $header;
        }

        foreach ($fieldAliases as $field => $aliases) {
            foreach ($aliases as $alias) {
                $key = $this->key($alias);
                if (isset($headerLookup[$key])) {
                    $mapping[$field] = $headerLookup[$key];
                    break;
                }
            }
        }

        return $mapping;
    }

    private function normalizeRow(string $entityType, array $rawValues, array $mapping): array
    {
        $values = [];

        foreach ($mapping as $field => $header) {
            $values[$field] = $rawValues[$header] ?? null;
        }

        if ($entityType === 'companies') {
            $values['name'] = $this->normalizer->text($values['name'] ?? null);
            $values['legal_name'] = $this->normalizer->text($values['legal_name'] ?? null);
            $values['inn'] = $this->normalizer->inn($values['inn'] ?? null);
            $values['phone'] = $this->normalizer->phone($values['phone'] ?? null);
            $values['email'] = $this->normalizer->email($values['email'] ?? null);
            $values['website'] = $this->normalizer->domain($values['website'] ?? null);
        }

        if ($entityType === 'contacts') {
            $values['full_name'] = $this->normalizer->text($values['full_name'] ?? null);
            $values['phone'] = $this->normalizer->phone($values['phone'] ?? null);
            $values['email'] = $this->normalizer->email($values['email'] ?? null);
            $values['position'] = $this->normalizer->text($values['position'] ?? null);
        }

        if ($entityType === 'leads') {
            $values['title'] = $this->normalizer->text($values['title'] ?? null);
            $values['need_description'] = $this->normalizer->text($values['need_description'] ?? null);
        }

        if ($entityType === 'deals') {
            $values['title'] = $this->normalizer->text($values['title'] ?? null);
        }

        foreach (['estimated_amount', 'amount'] as $moneyField) {
            if (isset($values[$moneyField])) {
                $values[$moneyField] = $this->money($values[$moneyField]);
            }
        }

        return array_filter($values, static fn ($value) => $value !== null && $value !== '');
    }

    private function validateRow(string $entityType, array $values): array
    {
        $errors = [];

        if ($entityType === 'companies' && empty($values['name'])) {
            $errors[] = trans_message('crm.import.errors.company_name_required');
        }

        if ($entityType === 'contacts' && empty($values['full_name'])) {
            $errors[] = trans_message('crm.import.errors.contact_name_required');
        }

        if ($entityType === 'leads' && empty($values['title'])) {
            $errors[] = trans_message('crm.import.errors.lead_title_required');
        }

        if ($entityType === 'deals') {
            if (empty($values['title'])) {
                $errors[] = trans_message('crm.import.errors.deal_title_required');
            }

            if (empty($values['company_id'])) {
                $errors[] = trans_message('crm.import.errors.deal_company_required');
            }
        }

        return $errors;
    }

    private function applyDecisions(CrmImportBatch $batch, array $decisions): void
    {
        foreach ($decisions as $decision) {
            CrmImportRow::query()
                ->where('batch_id', $batch->id)
                ->whereKey($decision['row_id'])
                ->update([
                    'decision' => $decision['decision'],
                    'created_entity_id' => $decision['target_id'] ?? null,
                ]);
        }
    }

    private function persistRow(
        int $organizationId,
        string $entityType,
        CrmImportRow $row,
        ?int $actorUserId
    ): CrmCompany|CrmContact|CrmLead|CrmDeal|null
    {
        $values = $row->normalized_values ?? [];

        if ($row->decision === 'update' && $row->created_entity_id !== null) {
            return $this->updateImportedRow($organizationId, $entityType, (string) $row->created_entity_id, $values, $actorUserId);
        }

        return match ($entityType) {
            'companies' => $this->registry->createCompany($organizationId, $values, $actorUserId),
            'contacts' => $this->registry->createContact($organizationId, $values, $actorUserId),
            'leads' => $this->registry->createLead($organizationId, $values, $actorUserId),
            'deals' => $this->registry->createDeal($organizationId, $values, $actorUserId),
            default => null,
        };
    }

    private function updateImportedRow(
        int $organizationId,
        string $entityType,
        string $targetId,
        array $values,
        ?int $actorUserId
    ): CrmCompany|CrmContact|CrmLead|CrmDeal|null {
        return match ($entityType) {
            'companies' => $this->registry->updateCompany($organizationId, $targetId, $values, $actorUserId),
            'contacts' => $this->registry->updateContact($organizationId, $targetId, $values, $actorUserId),
            'leads' => $this->registry->updateLead($organizationId, $targetId, $values, $actorUserId),
            'deals' => $this->registry->updateDeal($organizationId, $targetId, $values, $actorUserId),
            default => null,
        };
    }

    private function aliases(string $entityType): array
    {
        return match ($entityType) {
            'companies' => [
                'name' => ['name', 'company', 'company_name', 'название', 'компания'],
                'legal_name' => ['legal_name', 'юридическое название'],
                'inn' => ['inn', 'tax_id', 'инн'],
                'phone' => ['phone', 'телефон'],
                'email' => ['email', 'почта'],
                'website' => ['website', 'сайт'],
                'notes' => ['notes', 'примечание', 'заметка'],
            ],
            'contacts' => [
                'full_name' => ['full_name', 'name', 'contact', 'фио', 'контакт'],
                'position' => ['position', 'должность'],
                'phone' => ['phone', 'телефон'],
                'email' => ['email', 'почта'],
                'company_id' => ['company_id'],
                'notes' => ['notes', 'примечание', 'заметка'],
            ],
            'leads' => [
                'title' => ['title', 'lead', 'заявка', 'название'],
                'company_id' => ['company_id'],
                'contact_id' => ['contact_id'],
                'estimated_amount' => ['estimated_amount', 'сумма', 'бюджет'],
                'need_description' => ['need_description', 'потребность', 'описание'],
            ],
            'deals' => [
                'title' => ['title', 'deal', 'сделка', 'название'],
                'company_id' => ['company_id'],
                'primary_contact_id' => ['primary_contact_id', 'contact_id'],
                'amount' => ['amount', 'сумма'],
                'currency' => ['currency', 'валюта'],
                'expected_close_at' => ['expected_close_at', 'дата закрытия'],
            ],
            default => [],
        };
    }

    private function key(string $value): string
    {
        return preg_replace('/[^a-z0-9а-яё]+/u', '', mb_strtolower($value)) ?? mb_strtolower($value);
    }

    private function money(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], (string) $value);

        return is_numeric($normalized) ? (float) $normalized : null;
    }
}
