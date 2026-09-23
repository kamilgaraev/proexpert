<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\File;
use App\Services\Storage\FileService;

final class MobileEvidencePhotoPresenter
{
    public static function present(iterable $files): array
    {
        return collect($files)->map(static function (File $file): array {
            return [
                'id' => (int) $file->id,
                'name' => (string) ($file->original_name ?: $file->name),
                'url' => app(FileService::class)->temporaryUrl($file->path, 60, $file->organization),
            ];
        })->values()->all();
    }
}
