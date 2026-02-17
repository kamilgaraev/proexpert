<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\Models\ImportSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileStorageService
{
    // Using 'local' disk to ensure file is accessible by PhpSpreadsheet
    private const DISK = 'local';
    private const BASE_DIR = 'estimate-imports';

    public function store(UploadedFile $file, int $organizationId): array
    {
        $fileName = $file->getClientOriginalName();
        $fileSize = $file->getSize();
        $extension = $file->getClientOriginalExtension();
        
        // Generate secure filename
        $hash = Str::uuid()->toString();
        $storedName = "{$hash}.{$extension}";
        
        // Store file
        $relativePath = $file->storeAs(
            self::BASE_DIR . "/{$organizationId}", 
            $storedName, 
            ['disk' => self::DISK]
        );

        return [
            'path' => $relativePath,
            'name' => $fileName,
            'size' => $fileSize,
            'extension' => $extension,
            'disk' => self::DISK
        ];
    }

    public function getAbsolutePath(ImportSession $session): string
    {
        if (!$session->file_path) {
            throw new \RuntimeException("Import session {$session->id} has no file path");
        }
        
        return Storage::disk(self::DISK)->path($session->file_path);
    }

    public function delete(ImportSession $session): bool
    {
        if ($session->file_path && Storage::disk(self::DISK)->exists($session->file_path)) {
            return Storage::disk(self::DISK)->delete($session->file_path);
        }
        return false;
    }
}
