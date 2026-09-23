<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\CompletedWork;
use App\Models\ConstructionJournalEntry;
use App\Models\File;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Storage\FileService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

use function trans_message;

final class MobileFieldFilesService
{
    private const RECORD_TYPES = [
        'project' => Project::class,
        'completed_work' => CompletedWork::class,
        'construction_journal_entry' => ConstructionJournalEntry::class,
    ];

    private const READ_PERMISSIONS = [
        'projects.view',
        'completed_works.view',
        'construction-journal.view',
        'report_files.view',
    ];

    private const UPLOAD_PERMISSIONS = [
        'reports.photo_upload',
        'projects.upload_photos',
        'projects.upload_progress_photos',
    ];

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AccessController $access,
        private readonly MobileProjectAccessResolver $projectAccess,
        private readonly FileService $storage,
    ) {}

    /** @param array<string, mixed> $filters
     *  @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function paginate(User $user, int $organizationId, array $filters): array
    {
        $project = $this->authorizedProject($user, $organizationId, (int) $filters['project_id'], self::READ_PERMISSIONS);
        $query = $this->filesQuery($project, $organizationId);

        if (isset($filters['record_type'])) {
            $query->where('fileable_type', self::RECORD_TYPES[$filters['record_type']]);
        }
        if (isset($filters['record_id'])) {
            $query->where('fileable_id', (int) $filters['record_id']);
        }
        if (is_string($filters['q'] ?? null) && $filters['q'] !== '') {
            $search = '%'.$filters['q'].'%';
            $query->where(fn (Builder $files) => $files
                ->where('original_name', 'like', $search)
                ->orWhere('name', 'like', $search)
                ->orWhere('category', 'like', $search)
                ->orWhere('additional_info->description', 'like', $search));
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        $paginator = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(min((int) ($filters['per_page'] ?? 20), 50));
        $organization = Organization::query()->findOrFail($organizationId);
        $data = collect($paginator->items())
            ->map(fn (File $file): array => $this->payload($file, $organization, (int) $project->id))
            ->values()
            ->all();

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function show(User $user, int $organizationId, int $fileId, int $projectId): array
    {
        $project = $this->authorizedProject($user, $organizationId, $projectId, self::READ_PERMISSIONS);
        $file = $this->filesQuery($project, $organizationId)->whereKey($fileId)->firstOrFail();
        $organization = Organization::query()->findOrFail($organizationId);

        return $this->payload($file, $organization, $projectId);
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function upload(User $user, int $organizationId, array $input): array
    {
        $project = $this->authorizedProject($user, $organizationId, (int) $input['project_id'], self::UPLOAD_PERMISSIONS);
        $recordType = (string) $input['record_type'];
        $record = $this->resolveRecord($recordType, (int) $input['record_id'], $project);
        $file = $input['file'] ?? null;
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw ValidationException::withMessages(['file' => [trans_message('mobile_companions.errors.validation_failed')]]);
        }

        $organization = Organization::query()->findOrFail($organizationId);
        $mime = (string) $file->getMimeType();
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            default => throw ValidationException::withMessages([
                'file' => [trans_message('mobile_companions.errors.validation_failed')],
            ]),
        };
        $storageFile = new UploadedFile(
            (string) $file->getRealPath(),
            'upload.'.$extension,
            $mime,
            null,
            true,
        );
        $path = $this->storage->upload(
            $storageFile,
            'mobile/project-records/'.$project->id,
            null,
            'private',
            $organization,
            false,
            true,
        );
        if (! is_string($path) || $path === '') {
            throw new \RuntimeException('mobile_field_file_upload_failed');
        }

        try {
            $stored = DB::transaction(fn (): File => File::query()->create([
                'organization_id' => $organizationId,
                'fileable_id' => $record->getKey(),
                'fileable_type' => $record::class,
                'user_id' => (int) $user->id,
                'name' => basename($path),
                'original_name' => $this->safeOriginalName($file),
                'path' => $path,
                'mime_type' => (string) $file->getMimeType(),
                'size' => (int) $file->getSize(),
                'disk' => 's3',
                'type' => $input['file_type'],
                'category' => 'mobile_field',
                'additional_info' => [
                    'description' => $input['description'] ?? null,
                ],
            ]));
        } catch (Throwable $exception) {
            $this->storage->delete($path, $organization);
            throw $exception;
        }

        return $this->payload($stored, $organization, (int) $project->id);
    }

    private function authorizedProject(User $user, int $organizationId, int $projectId, array $permissions): Project
    {
        if (! $this->access->hasModuleAccess($organizationId, 'file-management')) {
            throw new DomainException(trans_message('mobile_companions.errors.permission_denied'));
        }
        $project = $this->projectAccess->resolve($user, $organizationId, $projectId, trans_message('mobile_companions.errors.item_not_found'));
        $allowed = false;
        foreach ($permissions as $permission) {
            if ($this->authorization->can($user, $permission, [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'strict_project_scope' => true,
            ])) {
                $allowed = true;
                break;
            }
        }
        if (! $allowed) {
            throw new DomainException(trans_message('mobile_companions.errors.permission_denied'));
        }

        return $project;
    }

    private function filesQuery(Project $project, int $organizationId): Builder
    {
        return File::query()
            ->where('files.organization_id', $organizationId)
            ->where(function (Builder $scope) use ($project): void {
                $scope->where(function (Builder $records) use ($project): void {
                    $records->where('fileable_type', Project::class)->where('fileable_id', $project->id);
                })->orWhere(function (Builder $records) use ($project): void {
                    $records->where('fileable_type', CompletedWork::class)
                        ->whereIn('fileable_id', CompletedWork::query()
                            ->select('id')->where('project_id', $project->id)->where('organization_id', $project->organization_id));
                })->orWhere(function (Builder $records) use ($project): void {
                    $records->where('fileable_type', ConstructionJournalEntry::class)
                        ->whereIn('fileable_id', ConstructionJournalEntry::query()
                            ->select('construction_journal_entries.id')
                            ->join('construction_journals', 'construction_journals.id', '=', 'construction_journal_entries.journal_id')
                            ->where('construction_journals.project_id', $project->id)
                            ->where('construction_journals.organization_id', $project->organization_id));
                });
            });
    }

    private function resolveRecord(string $recordType, int $recordId, Project $project): Model
    {
        $model = self::RECORD_TYPES[$recordType] ?? throw ValidationException::withMessages([
            'record_type' => [trans_message('mobile_companions.errors.validation_failed')],
        ]);
        $query = $model::query();
        if ($recordType === 'project') {
            $query->where('organization_id', $project->organization_id)->whereKey($project->id);
        } elseif ($recordType === 'completed_work') {
            $query->where('organization_id', $project->organization_id)->where('project_id', $project->id);
        } else {
            $query->whereHas('journal', fn (Builder $journal): Builder => $journal
                ->where('organization_id', $project->organization_id)->where('project_id', $project->id));
        }

        $record = $query->whereKey($recordId)->firstOrFail();
        return $record;
    }

    /** @return array<string, mixed> */
    private function payload(File $file, Organization $organization, int $projectId): array
    {
        $url = null;
        try {
            $disk = $this->storage->disk($organization);
            if ($disk->exists((string) $file->path)) {
                $url = $this->storage->temporaryUrl(
                    (string) $file->path,
                    5,
                    $organization,
                    ['ResponseContentDisposition' => 'attachment; filename="'.rawurlencode((string) $file->original_name).'"'],
                );
            }
        } catch (Throwable $exception) {
            Log::warning('mobile.field_files.download_url_failed', [
                'file_id' => $file->id,
                'organization_id' => $organization->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return [
            'id' => (int) $file->id,
            'project_id' => $projectId,
            'record_type' => array_search($file->fileable_type, self::RECORD_TYPES, true) ?: null,
            'record_id' => (int) $file->fileable_id,
            'name' => $file->original_name,
            'mime_type' => $file->mime_type,
            'size' => (int) $file->size,
            'file_type' => $file->type,
            'description' => $file->additional_info['description'] ?? null,
            'created_at' => $file->created_at?->toISOString(),
            'download_url' => $url,
        ];
    }

    private function safeOriginalName(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? 'file';

        return mb_substr($name !== '' ? $name : 'file', 0, 255);
    }
}
