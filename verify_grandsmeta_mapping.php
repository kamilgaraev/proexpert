<?php

use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta\GrandSmetaHandler;
use App\Models\ImportSession;
use PhpOffice\PhpSpreadsheet\IOFactory;

$session = ImportSession::latest()->first();
if (! $session) {
    echo "Session not found.\n";
    exit;
}

$fileStorage = app(\App\BusinessModules\Features\BudgetEstimates\Services\Import\FileStorageService::class);
$fileStorage->withLocalCopy($session, static function (string $fullPath): void {
    echo "Loading file: {$fullPath}\n";

    $content = IOFactory::load($fullPath);
    $sheet = $content->getActiveSheet();

    $handler = new GrandSmetaHandler;
    $detection = $handler->findHeaderAndMapping($sheet);

    echo 'Detected Header Row: '.$detection['header_row']."\n";
    echo "Detected Mapping:\n";
    print_r($detection['mapping']);
});

echo "Session ID: {$session->id}\n";
