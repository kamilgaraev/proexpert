<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Services;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentImport;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentImportItem;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Jobs\RegisterExecutiveDocumentImportItem;
use App\Exceptions\BusinessLogicException;
use App\Models\Organization;
use App\Services\Storage\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ExecutiveDocumentImportService
{
    public const MAX_FILES = 100;
    public const MAX_FILE_BYTES = 25 * 1024 * 1024;
    public const MAX_BATCH_BYTES = 250 * 1024 * 1024;
    public const EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];

    public function __construct(private readonly ExecutiveDocumentMutationGuard $guard, private readonly FileService $files) {}

    public function find(int $id, int $organizationId, int $userId): ExecutiveDocumentImport
    {
        $batch = ExecutiveDocumentImport::query()->where('organization_id', $organizationId)->find($id);
        if ($batch === null) throw new BusinessLogicException(trans_message('executive_documentation.errors.not_found'), 404);
        $this->assertBatchActor($batch, $userId, false);
        return $batch->load('items');
    }

    public function recent(ExecutiveDocumentSet $set, int $userId): array
    {
        $this->assertActor($set, $userId, false);
        return ExecutiveDocumentImport::query()->where('document_set_id', $set->id)->where('created_by', $userId)
            ->withCount('items')->latest('id')->limit(20)->get(['id', 'document_set_id', 'created_at'])->toArray();
    }

    public function create(ExecutiveDocumentSet $set, int $userId, string $operationKey, array $files): ExecutiveDocumentImport
    {
        $this->assertActor($set, $userId);
        Validator::make(['operation_key' => $operationKey, 'files' => $files], [
            'operation_key' => ['required', 'string', 'max:80'],
            'files' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES],
            'files.*.key' => ['required', 'string', 'max:80', 'distinct'],
            'files.*.name' => ['required', 'string', 'max:255'],
            'files.*.size' => ['required', 'integer', 'min:1', 'max:'.self::MAX_FILE_BYTES],
            'files.*.sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
        ])->validate();
        if (array_sum(array_column($files, 'size')) > self::MAX_BATCH_BYTES) {
            throw ValidationException::withMessages(['files' => trans_message('executive_documentation.errors.import_size_limit')]);
        }
        $manifest = [];
        foreach ($files as $index => $file) {
            if (preg_match('/[\\\\\/\x00-\x1f]/', $file['name']) || !in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
                throw ValidationException::withMessages(["files.{$index}.name" => trans_message('executive_documentation.errors.import_file_invalid')]);
            }
            $manifest[] = ['key' => $file['key'], 'name' => $file['name'], 'size' => (int) $file['size'], 'sha256' => $file['sha256']];
        }
        usort($manifest, static fn (array $left, array $right): int => strcmp($left['key'], $right['key']));
        $hash = hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($set, $userId, $operationKey, $manifest, $hash): ExecutiveDocumentImport {
            $set = ExecutiveDocumentSet::query()->lockForUpdate()->findOrFail($set->id);
            $this->assertActor($set, $userId);
            $existing = ExecutiveDocumentImport::query()->where('document_set_id', $set->id)->where('created_by', $userId)->where('operation_key', $operationKey)->first();
            if ($existing !== null) {
                if ($existing->manifest_hash !== $hash) {
                    throw new BusinessLogicException(trans_message('executive_documentation.errors.operation_conflict'), 409);
                }
                return $existing->load('items');
            }
            $batch = ExecutiveDocumentImport::query()->create(['organization_id' => $set->organization_id, 'document_set_id' => $set->id, 'created_by' => $userId, 'operation_key' => $operationKey, 'manifest_hash' => $hash]);
            $seen = [];
            foreach ($manifest as $file) {
                $duplicate = isset($seen[$file['sha256']]);
                $batch->items()->create([
                    'client_key' => $file['key'], 'original_name' => $file['name'], 'size' => $file['size'], 'content_hash' => $file['sha256'],
                    'status' => $duplicate ? 'duplicate' : 'awaiting_upload',
                    'errors' => $duplicate ? ['file' => trans_message('executive_documentation.errors.import_duplicate')] : null,
                ]);
                $seen[$file['sha256']] = true;
            }
            return $batch->load('items');
        });
    }

    public function upload(ExecutiveDocumentImport $batch, int $userId, int $itemId, UploadedFile $file): ExecutiveDocumentImportItem
    {
        $this->assertBatchActor($batch, $userId);
        Validator::make(['file' => $file], ['file' => ['required', File::types(self::EXTENSIONS)->max(25 * 1024)]])->validate();
        if (!in_array(strtolower($file->getClientOriginalExtension()), self::EXTENSIONS, true)) throw ValidationException::withMessages(['file' => trans_message('executive_documentation.errors.import_file_invalid')]);
        $path = null;
        $organization = Organization::query()->findOrFail($batch->organization_id);
        try {
            return DB::transaction(function () use ($batch, $userId, $itemId, $file, $organization, &$path): ExecutiveDocumentImportItem {
                $batch = ExecutiveDocumentImport::query()->lockForUpdate()->findOrFail($batch->id);
                $this->assertBatchActor($batch, $userId);
                $item = $batch->items()->lockForUpdate()->findOrFail($itemId);
                if ((int) $file->getSize() !== (int) $item->size || hash_file('sha256', $file->getRealPath()) !== $item->content_hash) {
                    throw ValidationException::withMessages(['file' => trans_message('executive_documentation.errors.import_content_mismatch')]);
                }
                if ($item->staged_path !== null || $item->status === 'completed') return $item;
                if (!in_array($item->status, ['awaiting_upload', 'invalid', 'expired'], true)) {
                    throw new BusinessLogicException(trans_message('executive_documentation.errors.operation_conflict'), 409);
                }
                $path = $this->files->upload($file, 'executive-documentation/import-'.$batch->id, null, 'private', $organization);
                if ($path === false) throw new BusinessLogicException(trans_message('executive_documentation.errors.version_file_upload_failed'), 503);
                $expired = $item->status === 'expired';
                $item->update(['staged_path' => $path, 'status' => $expired ? 'uploaded' : ($item->errors !== null ? 'invalid' : ($item->mapping !== null ? 'ready' : 'uploaded')), 'errors' => $expired ? null : $item->errors]);
                return $item;
            });
        } catch (\Throwable $exception) {
            if (is_string($path)) $this->files->disk($organization)->delete($path);
            throw $exception;
        }
    }

    public function map(ExecutiveDocumentImport $batch, int $userId, array $rows): ExecutiveDocumentImport
    {
        $this->assertBatchActor($batch, $userId);
        Validator::make(['rows' => $rows], ['rows' => ['required', 'array', 'max:100'], 'rows.*.id' => ['required', 'integer', 'distinct'], 'rows.*.mapping' => ['required', 'array']])->validate();
        return DB::transaction(function () use ($batch, $userId, $rows): ExecutiveDocumentImport {
            $batch = ExecutiveDocumentImport::query()->lockForUpdate()->findOrFail($batch->id);
            $this->assertBatchActor($batch, $userId);
            foreach ($rows as $row) {
                $item = $batch->items()->lockForUpdate()->findOrFail($row['id']);
                if (in_array($item->status, ['completed', 'queued', 'duplicate'], true)) continue;
                try {
                    $mapping = app(ExecutiveDocumentInput::class)->validateMapping($row['mapping'], $batch->documentSet);
                    $item->update(['mapping' => $mapping, 'errors' => null, 'status' => $item->staged_path === null ? 'awaiting_upload' : 'ready']);
                } catch (ValidationException $exception) {
                    $item->update(['mapping' => $row['mapping'], 'errors' => $exception->errors(), 'status' => 'invalid']);
                }
            }
            return $batch->load('items');
        });
    }

    public function start(ExecutiveDocumentImport $batch, int $userId, bool $retryFailedOnly = false): ExecutiveDocumentImport
    {
        $this->assertBatchActor($batch, $userId);
        return DB::transaction(function () use ($batch, $userId, $retryFailedOnly): ExecutiveDocumentImport {
            $batch = ExecutiveDocumentImport::query()->lockForUpdate()->findOrFail($batch->id);
            $this->assertBatchActor($batch, $userId);
            $items = $batch->items()->where('status', $retryFailedOnly ? 'failed' : 'ready')->lockForUpdate()->get();
            foreach ($items as $item) {
                $item->update(['status' => 'queued', 'attempt' => $item->attempt + 1, 'errors' => null]);
                RegisterExecutiveDocumentImportItem::dispatch($item->id, $item->attempt)->afterCommit();
            }
            return $batch->load('items');
        });
    }

    public function process(int $itemId, int $attempt): void
    {
        $candidate = ExecutiveDocumentImportItem::query()->findOrFail($itemId);
        $temporary = null;
        $created = null;
        $stagedPath = null;
        $organization = null;
        try {
            DB::transaction(function () use ($candidate, $attempt, &$temporary, &$created, &$stagedPath, &$organization): void {
                $batch = ExecutiveDocumentImport::query()->lockForUpdate()->findOrFail($candidate->import_id);
                $item = $batch->items()->lockForUpdate()->findOrFail($candidate->id);
                if ($item->status !== 'queued' || $item->attempt !== $attempt) return;
                $this->assertBatchActor($batch, (int) $batch->created_by);
                $organization = Organization::query()->findOrFail($batch->organization_id);
                $stagedPath = $item->staged_path;
                if (!is_string($stagedPath) || !$this->isStagedPath($stagedPath, $batch)) {
                    throw new BusinessLogicException(trans_message('executive_documentation.errors.import_content_mismatch'), 422);
                }
                $stream = $this->files->disk($organization)->readStream($stagedPath);
                if (!is_resource($stream)) throw new \RuntimeException('import_stream_unavailable');
                $temporary = tempnam(sys_get_temp_dir(), 'most-id-');
                if ($temporary === false) { fclose($stream); throw new \RuntimeException('import_temporary_file_unavailable'); }
                $output = fopen($temporary, 'wb');
                if ($output === false) { fclose($stream); throw new \RuntimeException('import_temporary_file_unwritable'); }
                try { $copied = stream_copy_to_stream($stream, $output, self::MAX_FILE_BYTES + 1); } finally { fclose($stream); fclose($output); }
                if ($copied !== $item->size || hash_file('sha256', $temporary) !== $item->content_hash) {
                    throw new BusinessLogicException(trans_message('executive_documentation.errors.import_content_mismatch'), 422);
                }
                $mapping = app(ExecutiveDocumentInput::class)->validateMapping($item->mapping ?? [], $batch->documentSet);
                $mapping['initial_version'] = ['version_number' => '1', 'file' => new UploadedFile($temporary, $item->original_name, null, null, true)];
                $mapping['metadata'] = [...($mapping['metadata'] ?? []), 'import_id' => $batch->id, 'import_item_id' => $item->id];
                $created = app(ExecutiveDocumentationService::class)->addDocument($batch->documentSet, (int) $batch->created_by, $mapping);
                $item->update(['status' => 'completed', 'document_id' => $created->id, 'errors' => null, 'staged_path' => null]);
            });
        } catch (\Throwable $exception) {
            if ($created !== null && $organization !== null) {
                foreach ($created->versions as $version) $this->files->disk($organization)->delete($version->file_url);
            }
            throw $exception;
        } finally {
            if (is_string($temporary) && is_file($temporary)) unlink($temporary);
        }
        if ($created !== null && $organization !== null && is_string($stagedPath)) {
            try { $this->files->disk($organization)->delete($stagedPath); } catch (\Throwable $exception) { report($exception); }
        }
    }

    public function fail(int $itemId, int $attempt, \Throwable $exception): void
    {
        $errors = $exception instanceof ValidationException ? $exception->errors() : ['registration' => trans_message('executive_documentation.errors.import_registration_failed')];
        ExecutiveDocumentImportItem::query()->whereKey($itemId)->where('attempt', $attempt)->where('status', 'queued')->update(['status' => 'failed', 'errors' => json_encode($errors, JSON_THROW_ON_ERROR)]);
    }

    public function expireStagedFiles(int $limit = 200): int
    {
        $expired = 0;
        $candidates = ExecutiveDocumentImportItem::query()->whereNotNull('staged_path')->where('status', '!=', 'completed')
            ->where('updated_at', '<', now()->subDays(7))->orderBy('id')->limit(min(max($limit, 1), 200))->get(['id', 'import_id']);
        foreach ($candidates as $candidate) {
            try {
                $expired += DB::transaction(function () use ($candidate): int {
                $batch = ExecutiveDocumentImport::query()->lockForUpdate()->findOrFail($candidate->import_id);
                $item = $batch->items()->lockForUpdate()->findOrFail($candidate->id);
                if ($item->status === 'completed' || $item->staged_path === null || $item->updated_at->gte(now()->subDays(7))) return 0;
                if (!$this->isStagedPath($item->staged_path, $batch)) {
                    \Illuminate\Support\Facades\Log::warning('executive_import_staging_path_invalid', ['item_id' => $item->id]);
                    return 0;
                }
                $disk = $this->files->disk(Organization::query()->findOrFail($batch->organization_id));
                if ($disk->exists($item->staged_path) && !$disk->delete($item->staged_path)) return 0;
                $item->update(['staged_path' => null, 'status' => 'expired', 'errors' => ['file' => trans_message('executive_documentation.errors.import_staging_expired')]]);
                return 1;
                });
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
        return $expired;
    }

    private function isStagedPath(string $path, ExecutiveDocumentImport $batch): bool
    {
        $prefix = preg_quote('org-'.$batch->organization_id.'/executive-documentation/import-'.$batch->id.'/', '#');
        return preg_match('#\A'.$prefix.'[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(?:pdf|docx?|xlsx?|jpe?g|png)\z#i', $path) === 1;
    }

    private function assertBatchActor(ExecutiveDocumentImport $batch, int $userId, bool $writable = true): void
    {
        if ((int) $batch->created_by !== $userId || $batch->documentSet === null || (int) $batch->documentSet->organization_id !== (int) $batch->organization_id) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.not_found'), 404);
        }
        $this->assertActor($batch->documentSet, $userId, $writable);
    }

    private function assertActor(ExecutiveDocumentSet $set, int $userId, bool $writable = true): void
    {
        $document = new ExecutiveDocument(['organization_id' => $set->organization_id, 'project_id' => $set->project_id, 'document_set_id' => $set->id]);
        $document->setRelation('documentSet', $set);
        $this->guard->assertActor($document, $userId, 'executive-documentation.create');
        if ($writable && $set->status->value !== 'draft') {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.transmitted_set_locked'), 409);
        }
    }
}
