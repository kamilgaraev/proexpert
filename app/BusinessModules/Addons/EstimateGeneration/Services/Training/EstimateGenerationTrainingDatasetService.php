<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Training;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationTrainingDataset;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationTrainingExample;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationTrainingFile;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNorm;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\WorkIntentClassifier;
use App\Models\ImportSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SystemAdmin;
use App\Services\Storage\FileService;
use App\Services\Storage\OrganizationStoragePath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

final class EstimateGenerationTrainingDatasetService
{
    public function __construct(
        private readonly FileService $fileService,
        private readonly TrainingEstimateRowsReader $rowsReader,
        private readonly TrainingEstimateRowNormalizer $rowNormalizer,
        private readonly WorkIntentClassifier $workIntentClassifier,
        private readonly EstimateGenerationLearningRecorder $learningRecorder,
        private readonly TrainingDatasetTrustPolicy $trustPolicy,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createFromFilament(array $data, ?SystemAdmin $actor): EstimateGenerationTrainingDataset
    {
        $organization = Organization::query()->find((int) ($data['organization_id'] ?? 0));

        if (! $organization instanceof Organization) {
            throw ValidationException::withMessages([
                'organization_id' => [trans_message('estimate_generation.training_organization_required')],
            ]);
        }

        $referenceFile = $data['reference_estimate_file'] ?? null;

        if (! $referenceFile instanceof TemporaryUploadedFile) {
            throw ValidationException::withMessages([
                'reference_estimate_file' => [trans_message('estimate_generation.training_reference_estimate_required')],
            ]);
        }

        $projectId = $this->nullableInt($data['project_id'] ?? null);

        if ($projectId !== null) {
            $project = Project::query()
                ->where('organization_id', (int) $organization->id)
                ->find($projectId);

            if (! $project instanceof Project) {
                throw ValidationException::withMessages([
                    'project_id' => [trans_message('estimate_generation.training_project_not_found')],
                ]);
            }
        }

        $dataset = EstimateGenerationTrainingDataset::query()->create([
            'uuid' => (string) Str::uuid(),
            'dataset_key' => (string) Str::uuid(),
            'version' => 1,
            'dataset_type' => EstimateGenerationTrainingDataset::TYPE_DEVELOPMENT,
            'scope' => 'organization',
            'organization_id' => (int) $organization->id,
            'project_id' => $projectId,
            'created_by_system_admin_id' => $actor?->id,
            'title' => trim((string) ($data['title'] ?? '')),
            'source_system' => (string) ($data['source_system'] ?? 'grandsmeta'),
            'status' => EstimateGenerationTrainingDataset::STATUS_DRAFT,
            'quality_status' => 'pending',
            'source_quality_score' => $this->boundedQuality($data['source_quality_score'] ?? 0.85),
            'region_name' => $this->nullableString($data['region_name'] ?? null),
            'period_name' => $this->nullableString($data['period_name'] ?? null),
            'notes' => $this->nullableString($data['notes'] ?? null),
            'stats' => [
                'uploaded_files' => 0,
                'parsed_rows' => 0,
                'accepted_rows' => 0,
                'skipped_rows' => 0,
                'learning_examples_created' => 0,
            ],
        ]);

        $this->storeUploadedFile($dataset, $organization, $referenceFile, EstimateGenerationTrainingFile::ROLE_REFERENCE_ESTIMATE);
        $this->storeUploadedFiles($dataset, $organization, $data['project_documents'] ?? [], EstimateGenerationTrainingFile::ROLE_PROJECT_DOCUMENT);
        $this->storeUploadedFiles($dataset, $organization, $data['drawings'] ?? [], EstimateGenerationTrainingFile::ROLE_DRAWING);
        $this->storeUploadedFiles($dataset, $organization, $data['scans'] ?? [], EstimateGenerationTrainingFile::ROLE_SCAN);
        $this->storeUploadedFiles($dataset, $organization, $data['statements'] ?? [], EstimateGenerationTrainingFile::ROLE_STATEMENT);

        $dataset->forceFill([
            'stats' => array_merge($dataset->stats ?? [], [
                'uploaded_files' => $dataset->files()->count(),
            ]),
        ])->save();

        return $dataset->fresh(['files']) ?? $dataset;
    }

    public function queueProcessing(EstimateGenerationTrainingDataset $dataset): void
    {
        $this->trustPolicy->assertCanProcess($dataset);
        $token = (string) Str::uuid();
        $affected = EstimateGenerationTrainingDataset::query()
            ->whereKey($dataset->getKey())
            ->where('organization_id', $dataset->organization_id)
            ->where('status', EstimateGenerationTrainingDataset::STATUS_DRAFT)
            ->update([
                'status' => EstimateGenerationTrainingDataset::STATUS_PROCESSING,
                'processing_token' => $token,
                'queued_at' => now(),
                'error_message' => null,
            ]);
        if ($affected !== 1) {
            throw new \DomainException('training_dataset_processing_claim_lost');
        }

        \App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationTrainingDatasetJob::dispatch((int) $dataset->id, $token);
    }

    public function appendVersion(EstimateGenerationTrainingDataset $source, ?SystemAdmin $actor): EstimateGenerationTrainingDataset
    {
        if (! in_array($source->status, [
            EstimateGenerationTrainingDataset::STATUS_APPROVED,
            EstimateGenerationTrainingDataset::STATUS_ARCHIVED,
        ], true)) {
            throw new \DomainException('dataset_version_source_not_final');
        }

        return DB::transaction(function () use ($source, $actor): EstimateGenerationTrainingDataset {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))', [(string) $source->organization_id, (string) $source->dataset_key]);
            $source = EstimateGenerationTrainingDataset::query()
                ->whereKey($source->getKey())
                ->where('organization_id', $source->organization_id)
                ->where('dataset_key', $source->dataset_key)
                ->where('version', $source->version)
                ->lockForUpdate()
                ->firstOrFail();
            if (! in_array($source->status, [EstimateGenerationTrainingDataset::STATUS_APPROVED, EstimateGenerationTrainingDataset::STATUS_ARCHIVED], true)) {
                throw new \DomainException('dataset_version_source_not_final');
            }
            $latestVersion = (int) EstimateGenerationTrainingDataset::query()
                ->where('organization_id', $source->organization_id)
                ->where('dataset_key', $source->dataset_key)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->value('version');

            return EstimateGenerationTrainingDataset::query()->create([
                'uuid' => (string) Str::uuid(),
                'dataset_key' => $source->dataset_key,
                'version' => $latestVersion + 1,
                'dataset_type' => $source->dataset_type,
                'scope' => 'organization',
                'organization_id' => $source->organization_id,
                'project_id' => $source->project_id,
                'created_by_system_admin_id' => $actor?->id,
                'title' => $source->title,
                'source_system' => $source->source_system,
                'status' => EstimateGenerationTrainingDataset::STATUS_DRAFT,
                'quality_status' => 'pending',
                'source_quality_score' => $source->source_quality_score,
                'region_name' => $source->region_name,
                'period_name' => $source->period_name,
                'notes' => $source->notes,
                'stats' => [],
            ]);
        });
    }

    /**
     * @return array<string, int>
     */
    public function process(EstimateGenerationTrainingDataset $dataset, string $processingToken): array
    {
        $claimed = EstimateGenerationTrainingDataset::query()
            ->whereKey($dataset->getKey())
            ->where('organization_id', $dataset->organization_id)
            ->where('status', EstimateGenerationTrainingDataset::STATUS_PROCESSING)
            ->where('processing_token', $processingToken)
            ->update(['processing_token' => null]);
        if ($claimed !== 1) {
            throw new \DomainException('training_dataset_processing_claim_lost');
        }
        $dataset = EstimateGenerationTrainingDataset::query()->whereKey($dataset->getKey())->firstOrFail();
        $tempPath = null;
        try {
            $dataset->loadMissing('files');
            $referenceFile = $dataset->files()->where('file_role', EstimateGenerationTrainingFile::ROLE_REFERENCE_ESTIMATE)->first();
            if (! $referenceFile instanceof EstimateGenerationTrainingFile) {
                throw new \RuntimeException(trans_message('estimate_generation.training_reference_estimate_required'));
            }
            $tempPath = $this->downloadToTemp($referenceFile);
            $stats = $this->parseAndRecord($dataset, $referenceFile, $tempPath);

            $dataset->forceFill([
                'status' => EstimateGenerationTrainingDataset::STATUS_REVIEW_REQUIRED,
                'quality_status' => 'needs_review',
                'stats' => $stats,
                'processed_at' => now(),
                'accepted_at' => null,
            ])->save();

            return $stats;
        } catch (Throwable $e) {
            $dataset->forceFill([
                'status' => EstimateGenerationTrainingDataset::STATUS_REJECTED,
                'quality_status' => 'failed',
                'error_message' => 'training_dataset_processing_failed',
                'processed_at' => now(),
            ])->save();

            Log::error('[EstimateGenerationTraining] Dataset processing failed', [
                'dataset_id' => $dataset->id,
                'failure_code' => 'training_dataset_processing_failed',
                'failure_fingerprint' => hash('sha256', $e::class.'|'.(string) $e->getCode()),
            ]);

            throw $e;
        } finally {
            if (is_string($tempPath) && is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * @return array<string, int>
     */
    private function parseAndRecord(
        EstimateGenerationTrainingDataset $dataset,
        EstimateGenerationTrainingFile $referenceFile,
        string $tempPath
    ): array {
        $session = $this->temporaryImportSession($dataset, $referenceFile);
        $rows = $this->rowsReader->rows($session, $tempPath);
        $stats = [
            'uploaded_files' => $dataset->files()->count(),
            'parsed_rows' => 0,
            'accepted_rows' => 0,
            'skipped_rows' => 0,
            'norm_not_found_rows' => 0,
            'unit_mismatch_rows' => 0,
            'learning_examples_created' => 0,
            'learning_examples_total' => 0,
        ];

        foreach ($rows as $row) {
            $stats['parsed_rows']++;
            $trainingExample = $this->upsertTrainingExample($dataset, $referenceFile, is_array($row) ? $row : (array) $row);

            if ($trainingExample->status !== EstimateGenerationTrainingExample::STATUS_ACCEPTED) {
                $stats['skipped_rows']++;

                continue;
            }

            $stats['accepted_rows']++;
        }

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function upsertTrainingExample(
        EstimateGenerationTrainingDataset $dataset,
        EstimateGenerationTrainingFile $referenceFile,
        array $row
    ): EstimateGenerationTrainingExample {
        $normalized = $this->rowNormalizer->normalize($row);
        $sourceRowHash = hash('sha256', json_encode([
            $normalized['row_number'],
            $normalized['section_path'],
            $normalized['work_name'],
            $normalized['norm_code'],
            $row,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: serialize($row));

        return EstimateGenerationTrainingExample::query()->updateOrCreate([
            'training_dataset_id' => (int) $dataset->id,
            'source_row_hash' => $sourceRowHash,
        ], [
            'organization_id' => (int) $dataset->organization_id,
            'dataset_version' => (int) $dataset->version,
            'estimate_file_id' => (int) $referenceFile->id,
            'row_number' => $normalized['row_number'],
            'section_name' => $normalized['section_name'],
            'section_path' => $normalized['section_path'],
            'work_name' => $normalized['work_name'],
            'work_unit' => $normalized['work_unit'],
            'work_quantity' => $normalized['work_quantity'],
            'norm_code' => $normalized['norm_code'],
            'status' => $normalized['status'],
            'quality_score' => $normalized['quality_score'],
            'quality_flags' => $normalized['quality_flags'],
            'raw_payload' => $normalized['raw_payload'],
            'source_refs' => $this->sourceRefs($dataset, $referenceFile, $normalized),
            'error_message' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);
    }

    /**
     * @return array{status: string, created: int}
     */
    public function recordApprovedExample(
        EstimateGenerationTrainingDataset $dataset,
        EstimateGenerationTrainingExample $trainingExample
    ): array {
        $dataset = EstimateGenerationTrainingDataset::query()
            ->whereKey($dataset->getKey())
            ->where('organization_id', $dataset->organization_id)
            ->where('dataset_key', $dataset->dataset_key)
            ->where('version', $dataset->version)
            ->firstOrFail();
        $trainingExample = $dataset->examples()
            ->whereKey($trainingExample->getKey())
            ->where('training_dataset_id', $dataset->id)
            ->firstOrFail();
        if (! $this->trustPolicy->canTrain($dataset)
            || $trainingExample->reviewed_by === null
            || $trainingExample->reviewed_at === null) {
            throw new \DomainException('training_example_not_reviewed_or_dataset_not_approved');
        }
        $normCode = (string) $trainingExample->norm_code;
        $norm = EstimateNorm::query()
            ->with('collection')
            ->where('code', $normCode)
            ->latest('id')
            ->first();

        if (! $norm instanceof EstimateNorm) {
            return ['status' => 'norm_not_found', 'created' => 0];
        }

        $flags = array_map('strval', $trainingExample->quality_flags ?? []);
        $workUnit = $trainingExample->work_unit;

        if ($workUnit === null || ! NormativeUnitNormalizer::compatible($workUnit, (string) $norm->unit)) {
            return ['status' => 'unit_mismatch', 'created' => 0];
        }

        $intent = $this->workIntentClassifier->classify([
            'name' => (string) $trainingExample->work_name,
            'unit' => $workUnit,
        ], [
            'section_title' => $trainingExample->section_name ?? $trainingExample->section_path,
            'source_system' => $dataset->source_system,
        ]);
        $intentPayload = [
            'scope' => $intent->scope,
            'action' => $intent->action,
            'object' => $intent->object,
            'material' => $intent->material,
            'system' => $intent->system,
            'expected_dimensions' => $intent->expectedDimensions,
            'signals' => $intent->signals,
            'confidence' => $intent->confidence,
        ];

        $attributes = [
            'organization_id' => (int) $dataset->organization_id,
            'project_id' => $dataset->project_id !== null ? (int) $dataset->project_id : null,
            'source_type' => 'superadmin_training_dataset',
            'source_entity_type' => 'estimate_generation_training_example',
            'source_entity_id' => (int) $trainingExample->id,
            'work_name' => (string) $trainingExample->work_name,
            'work_unit' => $workUnit,
            'work_quantity' => $trainingExample->work_quantity !== null ? (float) $trainingExample->work_quantity : null,
            'work_intent' => $intentPayload,
            'normative_dataset_version_id' => $norm->collection?->dataset_version_id !== null
                ? (int) $norm->collection->dataset_version_id
                : null,
            'estimate_norm_id' => (int) $norm->id,
            'norm_code' => $normCode,
            'normative_name' => (string) $norm->name,
            'normative_unit' => (string) $norm->unit,
            'decision_status' => 'reference_estimate_selected',
            'confidence' => 1.0,
            'is_positive' => true,
            'source_quality_score' => (float) $dataset->source_quality_score,
            'context_payload' => [
                'training_dataset_id' => (int) $dataset->id,
                'training_dataset_uuid' => (string) $dataset->uuid,
                'training_dataset_title' => (string) $dataset->title,
                'source_system' => (string) $dataset->source_system,
                'section_name' => $trainingExample->section_name,
                'section_path' => $trainingExample->section_path,
                'row_number' => $trainingExample->row_number,
                'region_name' => $dataset->region_name,
                'period_name' => $dataset->period_name,
            ],
            'source_refs' => $trainingExample->source_refs,
            'quality_flags' => array_values(array_unique([...$flags, 'unit_compatible', 'superadmin_reference'])),
            'accepted_at' => now(),
        ];

        $created = $this->learningRecorder->record($attributes);

        return ['status' => 'indexed', 'created' => $created];
    }

    public function archive(EstimateGenerationTrainingDataset $dataset): EstimateGenerationTrainingDataset
    {
        if ($dataset->status !== EstimateGenerationTrainingDataset::STATUS_APPROVED) {
            throw new \DomainException('dataset_archive_transition_not_allowed');
        }

        $dataset->forceFill(['status' => EstimateGenerationTrainingDataset::STATUS_ARCHIVED])->save();

        return $dataset->refresh();
    }

    /**
     * @param  array<int, string>  $extraFlags
     */
    private function markTrainingExample(EstimateGenerationTrainingExample $trainingExample, string $status, array $extraFlags): void
    {
        $trainingExample->forceFill([
            'status' => $status,
            'quality_flags' => array_values(array_unique([
                ...array_map('strval', $trainingExample->quality_flags ?? []),
                ...$extraFlags,
            ])),
        ])->save();
    }

    /**
     * @param  array<int, TemporaryUploadedFile>|TemporaryUploadedFile|mixed  $files
     */
    private function storeUploadedFiles(
        EstimateGenerationTrainingDataset $dataset,
        Organization $organization,
        mixed $files,
        string $role
    ): void {
        $files = is_array($files) ? $files : ($files instanceof TemporaryUploadedFile ? [$files] : []);

        foreach ($files as $file) {
            if ($file instanceof TemporaryUploadedFile) {
                $this->storeUploadedFile($dataset, $organization, $file, $role);
            }
        }
    }

    private function storeUploadedFile(
        EstimateGenerationTrainingDataset $dataset,
        Organization $organization,
        TemporaryUploadedFile $file,
        string $role
    ): EstimateGenerationTrainingFile {
        $extension = strtolower($file->getClientOriginalExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        $filename = (string) Str::uuid().($extension !== '' ? ".{$extension}" : '');
        $directory = "estimate-generation/training-datasets/{$dataset->uuid}/{$role}";
        $storagePath = OrganizationStoragePath::forOrganization((int) $organization->id, "{$directory}/{$filename}");
        $realPath = $file->getRealPath();

        if ($realPath === false || ! is_file($realPath)) {
            throw ValidationException::withMessages([
                'reference_estimate_file' => [trans_message('estimate_generation.training_upload_failed')],
            ]);
        }

        $content = file_get_contents($realPath);

        if ($content === false || $content === '') {
            throw ValidationException::withMessages([
                'reference_estimate_file' => [trans_message('estimate_generation.training_upload_failed')],
            ]);
        }

        $this->fileService->disk($organization)->put($storagePath, $content, 'private');

        return EstimateGenerationTrainingFile::query()->create([
            'training_dataset_id' => (int) $dataset->id,
            'organization_id' => (int) $organization->id,
            'file_role' => $role,
            'storage_disk' => 's3',
            'storage_path' => $storagePath,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => (int) $file->getSize(),
            'file_hash' => hash_file('sha256', $realPath) ?: null,
            'metadata' => [
                'extension' => $extension,
            ],
        ]);
    }

    private function downloadToTemp(EstimateGenerationTrainingFile $file): string
    {
        $extension = strtolower(pathinfo($file->storage_path, PATHINFO_EXTENSION));
        $tempPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.(string) Str::uuid().($extension !== '' ? ".{$extension}" : '');
        $organization = $file->organization;

        if (! $organization instanceof Organization) {
            throw new \RuntimeException(trans_message('estimate_generation.training_organization_required'));
        }

        $content = $this->fileService->disk($organization)->get((string) $file->storage_path);
        $written = file_put_contents($tempPath, $content);

        if ($written === false) {
            throw new \RuntimeException(trans_message('estimate_generation.training_upload_failed'));
        }

        return $tempPath;
    }

    private function temporaryImportSession(
        EstimateGenerationTrainingDataset $dataset,
        EstimateGenerationTrainingFile $file
    ): ImportSession {
        $session = new ImportSession([
            'organization_id' => (int) $dataset->organization_id,
            'status' => 'training_preview',
            'file_path' => (string) $file->storage_path,
            'file_name' => (string) $file->original_name,
            'file_size' => (int) $file->file_size,
            'file_format' => strtolower(pathinfo((string) $file->original_name, PATHINFO_EXTENSION)),
            'options' => [
                'source' => 'estimate_generation_training_dataset',
                'training_dataset_id' => (int) $dataset->id,
            ],
            'stats' => [],
        ]);
        $session->id = (string) Str::uuid();

        return $session;
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array<int, array<string, mixed>>
     */
    private function sourceRefs(
        EstimateGenerationTrainingDataset $dataset,
        EstimateGenerationTrainingFile $referenceFile,
        array $normalized
    ): array {
        $refs = [[
            'type' => 'training_reference_estimate',
            'training_dataset_id' => (int) $dataset->id,
            'training_dataset_uuid' => (string) $dataset->uuid,
            'file_id' => (int) $referenceFile->id,
            'filename' => (string) $referenceFile->original_name,
            'row_number' => $normalized['row_number'],
        ]];

        foreach ($dataset->files->where('file_role', '<>', EstimateGenerationTrainingFile::ROLE_REFERENCE_ESTIMATE)->take(20) as $file) {
            if ($file instanceof EstimateGenerationTrainingFile) {
                $refs[] = [
                    'type' => 'training_source_file',
                    'file_id' => (int) $file->id,
                    'file_role' => (string) $file->file_role,
                    'filename' => (string) $file->original_name,
                ];
            }
        }

        return $refs;
    }

    private function nullableInt(mixed $value): ?int
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function boundedQuality(mixed $value): float
    {
        return max(0.0, min(1.0, is_numeric($value) ? (float) $value : 0.85));
    }
}
