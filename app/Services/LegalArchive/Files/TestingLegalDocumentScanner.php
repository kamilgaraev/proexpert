<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Files;

use Illuminate\Http\UploadedFile;

final class TestingLegalDocumentScanner implements LegalDocumentScanner
{
    public function assertClean(UploadedFile $upload): void {}
}
