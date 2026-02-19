<?php

use App\Models\ImportSession;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta\GrandSmetaHandler;
use PhpOffice\PhpSpreadsheet\IOFactory;

$session = ImportSession::latest()->first();
if (!$session) {
    echo "Session not found.\n";
    exit;
}

$fileStorage = app(\App\BusinessModules\Features\BudgetEstimates\Services\Import\FileStorageService::class);
$fullPath = $fileStorage->getAbsolutePath($session);

echo "Session ID: {$session->id}\n";
echo "Loading file: {$fullPath}\n";

$content = IOFactory::load($fullPath);
$sheet = $content->getActiveSheet();

$handler = new GrandSmetaHandler();
$detection = $handler->findHeaderAndMapping($sheet);

echo "Detected Header Row: " . $detection['header_row'] . "\n";
echo "Detected Mapping:\n";
print_r($detection['mapping']);
