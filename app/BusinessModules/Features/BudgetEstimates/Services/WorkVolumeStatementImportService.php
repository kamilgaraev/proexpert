<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\BusinessModules\Features\BudgetEstimates\Http\Requests\WorkVolumeStatementRequest;
use App\BusinessModules\Features\BudgetEstimates\Models\WorkVolumeStatement;
use App\BusinessModules\Features\BudgetEstimates\Models\WorkVolumeStatementImport;
use App\Exceptions\BusinessLogicException;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class WorkVolumeStatementImportService
{
    public function __construct(
        private readonly WorkVolumeStatementService $statements,
        private readonly WorkVolumeTableReader $reader,
        private readonly FileService $files,
    ) {}

    public function paginate(User $actor, int $projectId, int $perPage = 25): LengthAwarePaginator
    {
        $this->statements->assertProjectAccess($actor, $projectId);
        return WorkVolumeStatementImport::query()->where('organization_id', $actor->current_organization_id)->where('project_id', $projectId)
            ->select(['id', 'project_id', 'status', 'source_file_name', 'source_file_hash', 'source_file_size', 'sheet_name', 'sheet_names', 'preview_version', 'statement_id', 'created_at', 'updated_at'])
            ->selectRaw('jsonb_array_length(preview_rows) AS row_count, jsonb_array_length(preview_errors) AS error_count')
            ->latest('id')->paginate(min(max($perPage, 1), 100))->through(fn (WorkVolumeStatementImport $import): array => $this->overview($import));
    }

    public function overview(WorkVolumeStatementImport $import): array
    {
        $attributes = $import->getAttributes();
        $rowCount = isset($attributes['row_count']) ? (int) $attributes['row_count'] : count($import->preview_rows ?? []);
        $errorCount = isset($attributes['error_count']) ? (int) $attributes['error_count'] : count($import->preview_errors ?? []);
        return $import->only(['id', 'project_id', 'status', 'source_file_name', 'source_file_hash', 'source_file_size', 'sheet_name', 'sheet_names', 'preview_version', 'statement_id', 'created_at', 'updated_at']) + [
            'row_count' => $rowCount, 'error_count' => $errorCount,
            'can_register' => $import->status === 'draft' && $errorCount === 0 && $rowCount > 0,
        ];
    }

    public function describe(User $actor, int $importId, int $page = 1, int $perPage = 100): array
    {
        $import = $this->scoped($actor, $importId, 'budget-estimates.view');
        $perPage = min(max($perPage, 1), 100);
        $lastPage = max(1, (int) ceil(count($import->preview_rows) / $perPage));
        $page = min(max($page, 1), $lastPage);
        $rows = array_slice($import->preview_rows, ($page - 1) * $perPage, $perPage);
        $keys = array_column($rows, 'import_row_key');
        $errors = array_values(array_filter($import->preview_errors, static fn (array $error): bool => ! isset($error['import_row_key']) || in_array($error['import_row_key'], $keys, true)));
        return $this->overview($import) + [
            'options' => $import->options, 'preview_rows' => $rows, 'preview_errors' => $errors,
            'pagination' => ['current_page' => $page, 'per_page' => $perPage, 'total' => count($import->preview_rows), 'last_page' => $lastPage],
        ];
    }

    public function sourceDownload(User $actor, int $importId): array
    {
        $import = $this->scoped($actor, $importId, 'budget-estimates.view');
        return ['url' => $this->files->temporaryDownloadUrl($import->source_file_path, 900), 'expires_in' => 900, 'filename' => $import->source_file_name, 'sha256' => $import->source_file_hash];
    }

    public function stage(User $actor, int $projectId, UploadedFile $file, array $options): WorkVolumeStatementImport
    {
        $this->statements->assertProjectAccess($actor, $projectId, 'budget-estimates.edit');
        $organizationId = (int) $actor->current_organization_id;
        $options = Validator::make($options, [
            'operation_key' => ['required', 'string', 'max:128'],
            'first_data_row' => ['required', 'integer', 'min:1', 'max:10000'],
            'sheet_name' => ['nullable', 'string', 'max:255'],
            'columns' => ['required', 'array:name,quantity,unit_code,place,line_key,measurement_formula,basis_revision'],
            'columns.name' => ['required', 'integer', 'min:0', 'max:127'],
            'columns.quantity' => ['required', 'integer', 'min:0', 'max:127'],
            'columns.unit_code' => ['required', 'integer', 'min:0', 'max:127'],
            'columns.place' => ['required', 'integer', 'min:0', 'max:127'],
            'columns.*' => ['integer', 'min:0', 'max:127', 'distinct'],
        ])->validate();
        $path = $file->getRealPath();
        $extension = strtolower($file->getClientOriginalExtension());
        if (! is_string($path) || ! is_readable($path) || ! in_array($extension, ['csv', 'xlsx'], true)
            || $file->getSize() <= 0 || $file->getSize() > 10 * 1024 * 1024 || mb_strlen($file->getClientOriginalName()) > 255) {
            throw $this->invalidFile();
        }
        $sha = hash_file('sha256', $path);
        if (! is_string($sha)) {
            throw $this->invalidFile();
        }
        $hash = $this->fingerprint([$sha, $file->getClientOriginalName(), $options]);
        $existing = $this->replay($organizationId, $projectId, $options['operation_key'], $hash);
        if ($existing !== null) {
            return $existing;
        }
        try {
            $table = $this->reader->read($path, $extension, $options['sheet_name'] ?? null);
        } catch (InvalidArgumentException) {
            throw $this->invalidFile();
        }
        $rawRows = array_values(array_filter($table['rows'], static fn (array $row): bool => $row['row_number'] >= $options['first_data_row']));
        $rows = [];
        foreach ($rawRows as $row) {
            $key = (string) Str::uuid();
            $mapped = [
                'import_row_key' => $key, 'line_key' => $key, 'source_row_number' => $row['row_number'],
                'excluded' => false, 'exclusion_reason' => null, 'formula_fields' => [],
            ];
            foreach ($options['columns'] as $field => $column) {
                $value = trim((string) ($row['values'][$column] ?? ''));
                $mapped[$field] = $field === 'place' ? ($value === '' ? [] : ['description' => $value]) : $value;
                if (in_array($column, $row['formula_columns'], true)) {
                    $mapped['formula_fields'][] = $field;
                }
            }
            $rows[] = $mapped;
        }
        $errors = $this->previewErrors($organizationId, $rows);
        $storedPath = 'org-'.$organizationId.'/work-volume-imports/'.Str::uuid().'.'.$extension;
        $stream = fopen($path, 'rb');
        if (! is_resource($stream)) {
            throw $this->invalidFile();
        }
        try {
            try {
                $stored = $this->files->putPrivate($storedPath, $stream, $file->getMimeType() ?: 'application/octet-stream', $sha);
            } finally {
                fclose($stream);
            }
            $import = DB::transaction(function () use ($actor, $organizationId, $projectId, $options, $hash, $stored, $file, $table, $rawRows, $rows, $errors): WorkVolumeStatementImport {
                Project::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
                $existing = $this->replay($organizationId, $projectId, $options['operation_key'], $hash);
                if ($existing !== null) {
                    return $existing;
                }
                return WorkVolumeStatementImport::query()->create([
                    'organization_id' => $organizationId, 'project_id' => $projectId, 'created_by' => $actor->id,
                    'operation_key' => $options['operation_key'], 'operation_hash' => $hash,
                    'source_file_path' => $stored->key, 'source_file_hash' => $stored->sha256,
                    'source_file_name' => $file->getClientOriginalName(), 'source_file_size' => $stored->sizeBytes,
                    'sheet_name' => $table['sheet_name'], 'sheet_names' => $table['sheet_names'],
                    'options' => $options, 'raw_rows' => $rawRows, 'preview_rows' => $rows, 'preview_errors' => $errors,
                    'preview_hash' => $this->fingerprint($rows), 'preview_version' => 1, 'status' => 'draft',
                ]);
            });
        } catch (Throwable $exception) {
            $this->cleanup($storedPath, $organizationId);
            throw $exception;
        }
        if ($import->source_file_path !== $storedPath) {
            $this->cleanup($storedPath, $organizationId);
        }
        return $import;
    }

    public function savePreview(User $actor, int $importId, int $expectedVersion, array $rows): WorkVolumeStatementImport
    {
        $import = $this->scoped($actor, $importId, 'budget-estimates.edit');
        Validator::make(['rows' => $rows], [
            'rows' => ['present', 'array', 'max:10000'], 'rows.*' => ['array'],
            'rows.*.import_row_key' => ['required', 'uuid', 'distinct'],
            'rows.*.line_key' => ['present', 'string', 'max:128'],
            'rows.*.name' => ['present', 'string', 'max:10000'],
            'rows.*.quantity' => ['present', 'string', 'max:10000'],
            'rows.*.unit_code' => ['present', 'string', 'max:10000'],
            'rows.*.place' => ['present', 'array'],
            'rows.*.excluded' => ['required', 'boolean'],
            'rows.*.exclusion_reason' => ['nullable', 'string', 'max:2000'],
            'rows.*.measurement_formula' => ['nullable', 'string', 'max:10000'],
            'rows.*.basis_revision' => ['nullable', 'string', 'max:10000'],
            'rows.*.estimate_item_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();
        return DB::transaction(function () use ($import, $expectedVersion, $rows): WorkVolumeStatementImport {
            $import = WorkVolumeStatementImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
            $originalRows = collect($import->preview_rows)->keyBy('import_row_key');
            if (count($rows) !== $originalRows->count() || collect($rows)->pluck('import_row_key')->diff($originalRows->keys())->isNotEmpty()) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.import_rows_missing'), 422);
            }
            $rows = array_map(static function (array $row) use ($originalRows): array {
                $original = $originalRows[$row['import_row_key']];
                $result = array_intersect_key($row, array_flip(['import_row_key', 'line_key', 'name', 'quantity', 'unit_code', 'place', 'excluded', 'exclusion_reason', 'measurement_formula', 'basis_revision', 'estimate_item_id']));
                $result['source_row_number'] = $original['source_row_number'];
                $result['formula_fields'] = $original['formula_fields'];
                return $result;
            }, $rows);
            $hash = $this->fingerprint($rows);
            if ($import->status === 'draft' && $import->preview_hash === $hash) {
                return $import;
            }
            if ($import->status !== 'draft' || $import->preview_version !== $expectedVersion) {
                throw $this->conflict();
            }
            $import->forceFill([
                'preview_rows' => $rows, 'preview_errors' => $this->previewErrors((int) $import->organization_id, $rows),
                'preview_hash' => $hash, 'preview_version' => $expectedVersion + 1,
            ])->save();
            return $import;
        });
    }

    public function patchPreview(User $actor, int $importId, int $expectedVersion, array $rows): WorkVolumeStatementImport
    {
        $import = $this->scoped($actor, $importId, 'budget-estimates.edit');
        Validator::make(['rows' => $rows], [
            'rows' => ['required', 'array', 'max:1000'],
            'rows.*' => ['array'], 'rows.*.import_row_key' => ['required', 'uuid', 'distinct'],
        ])->validate();
        return DB::transaction(function () use ($actor, $import, $expectedVersion, $rows): WorkVolumeStatementImport {
            $import = WorkVolumeStatementImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
            $patches = collect($rows)->keyBy('import_row_key');
            if ($patches->keys()->diff(array_column($import->preview_rows, 'import_row_key'))->isNotEmpty()) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.import_rows_missing'), 422);
            }
            $merged = array_map(static fn (array $row): array => $patches[$row['import_row_key']] ?? $row, $import->preview_rows);
            return $this->savePreview($actor, $import->id, $expectedVersion, $merged);
        });
    }

    public function register(User $actor, int $importId, int $expectedVersion, array $metadata): WorkVolumeStatement
    {
        $import = $this->scoped($actor, $importId, 'budget-estimates.edit');
        $metadata = Validator::make($metadata, [
            'name' => ['nullable', 'string', 'max:255'], 'basis_revision' => ['nullable', 'string', 'max:255'],
            'change_reason' => ['nullable', 'string', 'max:2000'], 'based_on_statement_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();
        $hash = $this->fingerprint([$expectedVersion, $metadata]);
        return DB::transaction(function () use ($actor, $import, $expectedVersion, $metadata, $hash): WorkVolumeStatement {
            Project::query()->whereKey($import->project_id)->lockForUpdate()->firstOrFail();
            $import = WorkVolumeStatementImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
            if ($import->status === 'registered' && $import->registration_hash === $hash) {
                return WorkVolumeStatement::query()->whereKey($import->statement_id)->with('lines')->firstOrFail();
            }
            if ($import->status !== 'draft' || $import->preview_version !== $expectedVersion) {
                throw $this->conflict();
            }
            if ($this->previewErrors((int) $import->organization_id, $import->preview_rows) !== []) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.import_unresolved'), 422);
            }
            $lines = array_values(array_filter($import->preview_rows, static fn (array $row): bool => ! ($row['excluded'] ?? false)));
            foreach ($lines as &$line) {
                $line['metadata'] = ['source_import_id' => $import->id, 'source_row_number' => $line['source_row_number']];
            }
            unset($line);
            $payload = [...$metadata, 'lines' => $lines, 'operation_key' => 'wvs-import-'.$import->id, '_source_import_id' => $import->id];
            if (! empty($metadata['based_on_statement_id'])) {
                $source = WorkVolumeStatement::query()->whereKey($metadata['based_on_statement_id'])
                    ->where('organization_id', $import->organization_id)->where('project_id', $import->project_id)->first();
                if ($source === null) {
                    throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.scope_invalid'), 404);
                }
                $statement = $this->statements->createRevision($actor, $source, $payload);
            } else {
                $statement = $this->statements->createDraft($actor, (int) $import->project_id, $payload);
            }
            $statement->forceFill(['source_import_id' => $import->id, 'source_file_path' => $import->source_file_path, 'source_file_hash' => $import->source_file_hash])->save();
            $import->forceFill(['status' => 'registered', 'statement_id' => $statement->id, 'registration_hash' => $hash])->save();
            return $statement->load('lines');
        });
    }

    private function scoped(User $actor, int $importId, string $permission): WorkVolumeStatementImport
    {
        $import = WorkVolumeStatementImport::query()->whereKey($importId)->where('organization_id', $actor->current_organization_id)->first();
        if ($import === null) {
            throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.scope_invalid'), 404);
        }
        $this->statements->assertProjectAccess($actor, (int) $import->project_id, $permission);
        return $import;
    }

    private function replay(int $organizationId, int $projectId, string $key, string $hash): ?WorkVolumeStatementImport
    {
        $import = WorkVolumeStatementImport::query()->where('organization_id', $organizationId)->where('project_id', $projectId)->where('operation_key', $key)->first();
        if ($import !== null && $import->operation_hash !== $hash) {
            throw $this->conflict();
        }
        return $import;
    }

    private function previewErrors(int $organizationId, array $rows): array
    {
        $active = array_values(array_filter($rows, static fn (array $row): bool => ! ($row['excluded'] ?? false)));
        $errors = [];
        $validation = Validator::make(['lines' => $active], (new WorkVolumeStatementRequest())->rules());
        foreach ($validation->errors()->messages() as $field => $messages) {
            $error = ['code' => 'field_invalid', 'field' => $field, 'messages' => $messages];
            if (preg_match('/^lines\.(\d+)\.(.+)$/', $field, $matches) === 1) {
                $error['import_row_key'] = $active[(int) $matches[1]]['import_row_key'];
                $error['field'] = $matches[2];
            }
            $errors[] = $error;
        }
        $units = MeasurementUnit::query()->where('organization_id', $organizationId)->pluck('short_name')->all();
        $seenKeys = $seenScopes = [];
        foreach ($rows as $row) {
            $key = $row['import_row_key'];
            if ($row['excluded'] ?? false) {
                if (trim((string) ($row['exclusion_reason'] ?? '')) === '') {
                    $errors[] = ['code' => 'exclusion_reason_required', 'import_row_key' => $key];
                }
                continue;
            }
            if (! in_array($row['unit_code'] ?? '', $units, true)) {
                $errors[] = ['code' => 'unit_unresolved', 'import_row_key' => $key];
            }
            $scope = $this->fingerprint([$row['name'] ?? '', $row['unit_code'] ?? '', $row['place'] ?? [], $row['basis_revision'] ?? null, $row['measurement_formula'] ?? null]);
            $lineKey = strtolower($row['line_key']);
            if (isset($seenKeys[$lineKey]) || isset($seenScopes[$scope])) {
                $errors[] = ['code' => 'duplicate_row', 'import_row_key' => $key];
            }
            $seenKeys[$lineKey] = $seenScopes[$scope] = true;
            foreach ($row['formula_fields'] ?? [] as $field) {
                $value = $field === 'place' ? ($row['place']['description'] ?? '') : ($row[$field] ?? '');
                if ($field !== 'measurement_formula' && is_string($value) && str_starts_with($value, '=')) {
                    $errors[] = ['code' => 'formula_unresolved', 'import_row_key' => $key, 'field' => $field];
                }
            }
        }
        return $errors;
    }

    private function fingerprint(mixed $value): string
    {
        $canonical = function (mixed $item) use (&$canonical): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }
            return array_map($canonical, $item);
        };
        return hash('sha256', json_encode($canonical($value), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function cleanup(string $path, int $organizationId): void
    {
        try {
            $this->files->deleteCurrent($path);
        } catch (Throwable) {
            Log::warning('wvs_import_cleanup_failed', ['organization_id' => $organizationId, 'path' => $path]);
        }
    }

    private function conflict(): BusinessLogicException
    {
        return new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.operation_conflict'), 409);
    }

    private function invalidFile(): BusinessLogicException
    {
        return new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.import_file_invalid'), 422);
    }
}
