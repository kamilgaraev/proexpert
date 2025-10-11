<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $permissionChecker = app(\App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker::class);
    echo "AIPermissionChecker: OK\n";
} catch (Exception $e) {
    echo "AIPermissionChecker: ERROR - " . $e->getMessage() . "\n";
}

try {
    $action = app(\App\BusinessModules\Features\AIAssistant\Actions\MeasurementUnits\CreateMeasurementUnitAction::class);
    echo "CreateMeasurementUnitAction: OK\n";
} catch (Exception $e) {
    echo "CreateMeasurementUnitAction: ERROR - " . $e->getMessage() . "\n";
}

try {
    $service = app(\App\Services\MeasurementUnit\MeasurementUnitService::class);
    echo "MeasurementUnitService: OK\n";
} catch (Exception $e) {
    echo "MeasurementUnitService: ERROR - " . $e->getMessage() . "\n";
}
