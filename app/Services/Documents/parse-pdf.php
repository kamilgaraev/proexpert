<?php

declare(strict_types=1);

use Smalot\PdfParser\Parser;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__, 3).'/vendor/autoload.php';

try {
    $path = $argv[1] ?? '';
    if (!is_file($path) || filesize($path) > 25_000_000) {
        exit(2);
    }
    $document = (new Parser)->parseFile($path);
    $pages = $document->getPages();
    $texts = [];
    $bytes = 0;
    if (($argv[2] ?? '') === 'text') {
        if (count($pages) > 300) {
            exit(2);
        }
        foreach ($pages as $page) {
            $text = trim($page->getText());
            $bytes += strlen($text);
            if ($bytes > 2_000_000) {
                exit(2);
            }
            $texts[] = $text;
        }
    }
    $output = json_encode([
        'page_count' => count($pages),
        'pages' => $texts,
        'details' => $document->getDetails(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    if (strlen($output) > 8_000_000) {
        exit(2);
    }
    echo $output;
} catch (Throwable) {
    exit(2);
}
