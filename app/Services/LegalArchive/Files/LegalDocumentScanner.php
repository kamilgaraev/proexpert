<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Files;

use Illuminate\Http\UploadedFile;

interface LegalDocumentScanner
{
    public function assertClean(UploadedFile $upload): void;
}
