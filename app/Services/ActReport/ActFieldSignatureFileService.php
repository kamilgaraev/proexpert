<?php

declare(strict_types=1);

namespace App\Services\ActReport;

use App\Exceptions\BusinessLogicException;
use App\Models\ContractPerformanceAct;
use App\Models\File;
use App\Models\Organization;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Support\Str;

final class ActFieldSignatureFileService
{
    public function __construct(private readonly FileService $storage) {}

    public function store(ContractPerformanceAct $act, string $pngBytes, User $user): File
    {
        $act->loadMissing('contract.organization');
        $organization = $act->contract?->organization;
        if (! $organization instanceof Organization) {
            throw new BusinessLogicException(trans_message('act_reports.organization_not_found'), 404);
        }

        $filename = 'field-signature-'.Str::uuid().'.png';
        $path = $this->storage->putContent(
            $pngBytes,
            "acts/{$act->id}/field-confirmations",
            $filename,
            'private',
            $organization
        );
        if (! is_string($path) || $path === '') {
            throw new BusinessLogicException(trans_message('act_reports.file_upload_failed'), 500);
        }

        try {
            return File::query()->create([
                'organization_id' => (int) $organization->id,
                'fileable_id' => (int) $act->id,
                'fileable_type' => ContractPerformanceAct::class,
                'user_id' => (int) $user->id,
                'name' => $filename,
                'original_name' => $filename,
                'path' => $path,
                'mime_type' => 'image/png',
                'size' => strlen($pngBytes),
                'disk' => 's3',
                'type' => 'document',
                'category' => 'field_acceptance_signature',
                'additional_info' => [
                    'purpose' => 'field_acceptance_evidence',
                    'legal_signature' => false,
                ],
            ]);
        } catch (\Throwable $exception) {
            $this->storage->disk($organization)->delete($path);

            throw $exception;
        }
    }

    public function delete(File $file): void
    {
        $this->deleteObject($file);
        $file->delete();
    }

    public function deleteObject(File $file): void
    {
        $file->loadMissing('organization');
        $this->storage->disk($file->organization)->delete((string) $file->path);
    }
}
