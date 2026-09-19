<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

$directory = $argv[1] ?? '';
if ($directory === '' || !is_dir($directory)) {
    throw new RuntimeException('An existing output directory is required');
}
$html = \Tests\Unit\Contract\ContractDocumentExporterTest::html();
$exporter = new \App\Services\Contract\ContractDocumentExporter;
foreach (['pdf', 'docx'] as $format) {
    $path = $directory.DIRECTORY_SEPARATOR.'contract-builder-sample.'.$format;
    file_put_contents($path, $exporter->render($html, $format));
    echo $path.PHP_EOL;
}
