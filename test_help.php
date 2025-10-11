<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$recognizer = app(\App\BusinessModules\Features\AIAssistant\Services\IntentRecognizer::class);

$queries = [
    'что ты умеешь',
    'помоги',
    'функционал',
    'какие возможности',
    'что можешь делать',
    'справка',
    'помощь',
];

foreach ($queries as $query) {
    $result = $recognizer->recognize($query);
    echo "Query: '$query'\nResult: '$result'\n\n";
}
