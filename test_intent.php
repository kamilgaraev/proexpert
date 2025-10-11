<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$recognizer = app(\App\BusinessModules\Features\AIAssistant\Services\IntentRecognizer::class);

$queries = [
    'какие есть единицы измерения',
    'покажи единицы измерения',
    'список единиц',
    'создай единицу измерения метры',
    'измени единицу №5',
    'удали единицу 3'
];

foreach ($queries as $query) {
    $result = $recognizer->recognize($query);
    echo "Query: '$query'\nResult: '$result'\n\n";
}
