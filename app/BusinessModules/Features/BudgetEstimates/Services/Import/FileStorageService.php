<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\Models\ImportSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileStorageService
{
    private const DISK = 's3';
    private const BASE_DIR = 'estimate-imports';

    public function store(UploadedFile $file, int $organizationId): array
    {
        $fileName  = $file->getClientOriginalName();
        $fileSize  = $file->getSize();
        $extension = $file->getClientOriginalExtension();

        $storedName   = Str::uuid()->toString() . '.' . $extension;
        $relativePath = self::BASE_DIR . "/org-{$organizationId}/" . $storedName;

        Storage::disk(self::DISK)->put($relativePath, file_get_contents($file->getRealPath()), 'private');

        Log::info('[FileStorageService] File uploaded to S3', [
            'path'            => $relativePath,
            'organization_id' => $organizationId,
            'size'            => $fileSize,
        ]);

        return [
            'path'      => $relativePath,
            'name'      => $fileName,
            'size'      => $fileSize,
            'extension' => $extension,
            'disk'      => self::DISK,
        ];
    }

    public function getAbsolutePath(ImportSession $session): string
    {
        if (!$session->file_path) {
            throw new \RuntimeException("Import session {$session->id} has no file path");
        }

        if (!Storage::disk(self::DISK)->exists($session->file_path)) {
            throw new \RuntimeException("File \"{$session->file_path}\" does not exist in S3");
        }

        $extension   = pathinfo($session->file_path, PATHINFO_EXTENSION);
        $tmpPath     = sys_get_temp_dir() . '/' . Str::uuid()->toString() . '.' . $extension;
        $fileContent = Storage::disk(self::DISK)->get($session->file_path);

        file_put_contents($tmpPath, $fileContent);

        Log::info('[FileStorageService] File downloaded from S3 to tmp', [
            'session_id' => $session->id,
            's3_path'    => $session->file_path,
            'tmp_path'   => $tmpPath,
        ]);

        return $tmpPath;
    }

    public function delete(ImportSession $session): bool
    {
        if ($session->file_path && Storage::disk(self::DISK)->exists($session->file_path)) {
            return Storage::disk(self::DISK)->delete($session->file_path);
        }

        return false;
    }
}
