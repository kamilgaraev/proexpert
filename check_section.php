<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ImportSession;
use App\Models\Estimate;
use App\Models\EstimateSection;

$session = ImportSession::latest()->first();
echo "Session file: " . $session->file_path . "\n";
$fullPath = storage_path('app/' . $session->file_path);

use PhpOffice\PhpSpreadsheet\IOFactory;
if (file_exists($fullPath)) {
    $spreadsheet = IOFactory::load($fullPath);
    $sheet = $spreadsheet->getActiveSheet();
    $row = 0;
    foreach ($sheet->getRowIterator() as $r) {
        $row++;
        if ($row >= 40 && $row <= 60) {
            $data = [];
            foreach ($r->getCellIterator() as $c) {
                $data[$c->getColumn()] = $c->getCalculatedValue();
            }
            if (in_array('Раздел 1. АПС', $data, true) || (isset($data['A']) && str_contains((string)$data['A'], 'Раздел')) || (isset($data['B']) && str_contains((string)$data['B'], 'Раздел'))) {
                dump('Row ' . $row, $data);
            }
        }
    }
}
