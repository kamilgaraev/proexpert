<?php

declare(strict_types=1);

namespace App\Services\ActReport;

use App\Exceptions\BusinessLogicException;
use App\Models\ContractPerformanceAct;
use App\Models\File;
use App\Models\PersonalFile;
use App\Models\User;
use App\Services\Storage\FileService;
use App\Services\Storage\PersonalFileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function trans_message;

class ActReportFileService
{
    public function __construct(
        private readonly FileService $fileService,
        private readonly PersonalFileService $personalFiles,
        private readonly ActReportAccessService $accessService,
        private readonly ActReportWorkflowService $workflowService
    ) {}

    public function upload(
        ContractPerformanceAct $act,
        UploadedFile $uploadedFile,
        ?User $user,
        string $category,
        ?string $description
    ): File {
        $act->loadMissing('contract.organization');

        $path = $this->fileService->upload(
            $uploadedFile,
            "acts/{$act->id}/documents",
            null,
            'private',
            $act->contract->organization
        );

        if (! $path) {
            throw new BusinessLogicException(trans_message('act_reports.file_upload_failed'), 500);
        }

        return File::query()->create([
            'organization_id' => $act->contract->organization_id,
            'fileable_id' => $act->id,
            'fileable_type' => ContractPerformanceAct::class,
            'user_id' => $user?->id,
            'name' => basename($path),
            'original_name' => $uploadedFile->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $uploadedFile->getClientMimeType(),
            'size' => $uploadedFile->getSize(),
            'disk' => 's3',
            'type' => 'document',
            'category' => $category,
            'additional_info' => [
                'description' => $description,
            ],
        ]);
    }

    public function uploadSigned(
        ContractPerformanceAct $act,
        UploadedFile $uploadedFile,
        ?User $user,
        ?string $description
    ): ContractPerformanceAct {
        $file = $this->upload(
            $act,
            $uploadedFile,
            $user,
            'signed_act',
            $description ?? trans_message('act_reports.signed_file_description')
        );

        return $this->workflowService->markSigned($act, $file->id, (int) $user?->id);
    }

    public function list(ContractPerformanceAct $act): Collection
    {
        return $act->files()
            ->where('organization_id', (int) $act->contract->organization_id)
            ->with('user')
            ->latest()
            ->get()
            ->map(fn (File $file): array => $this->format($file))
            ->values();
    }

    public function download(ContractPerformanceAct $act, mixed $file): StreamedResponse
    {
        $file = $this->accessService->resolveActFile($act, $file);
        $storage = $this->fileService->disk($act->contract->organization);

        if (! $storage->exists($file->path)) {
            throw new BusinessLogicException(trans_message('act_reports.file_not_found'), 404);
        }

        $stream = $storage->readStream($file->path);

        if ($stream === false) {
            throw new BusinessLogicException(trans_message('act_reports.file_not_found'), 404);
        }

        return Response::streamDownload(
            static function () use ($stream): void {
                fpassthru($stream);

                if (is_resource($stream)) {
                    fclose($stream);
                }
            },
            (string) ($file->original_name ?: $file->name ?: 'act-file'),
            [
                'Content-Type' => $file->mime_type ?: 'application/octet-stream',
            ]
        );
    }

    public function delete(ContractPerformanceAct $act, mixed $file): void
    {
        $file = $this->accessService->resolveActFile($act, $file);

        $this->fileService->disk($act->contract->organization)->delete($file->path);
        $file->delete();
    }

    public function copyToPersonalStorage(ContractPerformanceAct $act, mixed $file, User $user): PersonalFile
    {
        $file = $this->accessService->resolveActFile($act, $file);
        $filename = trim((string) ($file->original_name ?: $file->name));
        $stream = $this->fileService->readCurrent((string) $file->path);

        try {
            return $this->personalFiles->storeStream(
                (int) $act->contract->organization_id,
                (int) $user->id,
                $stream,
                $filename !== '' ? $filename : 'act-file',
                (string) ($file->mime_type ?: 'application/octet-stream'),
                'acts',
            );
        } finally {
            fclose($stream);
        }
    }

    public function format(File $file): array
    {
        return [
            'id' => $file->id,
            'name' => $file->original_name,
            'size' => $file->size,
            'mime_type' => $file->mime_type,
            'category' => $file->category,
            'uploaded_by' => $file->user?->name ?? '',
            'uploaded_at' => $file->created_at?->toIso8601String(),
            'description' => $file->additional_info['description'] ?? null,
        ];
    }
}
