<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Editor;

interface EditorDocumentFetcher
{
    public function fetch(string $url, string $expectedExtension): DownloadedEditorDocument;
}
