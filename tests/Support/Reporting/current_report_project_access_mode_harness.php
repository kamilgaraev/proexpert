<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$source = file_get_contents(
    $root.'/app/BusinessModules/Core/Reporting/Infrastructure/Execution/LaravelCurrentReportScopeAuthorizer.php',
);

assert(is_string($source));
assert(str_contains($source, 'use App\\Enums\\UserProjectAccessMode;'));
assert(str_contains($source, 'UserProjectAccessMode::ALL_PROJECTS->value'));
assert(! str_contains($source, "project_access_mode ?? 'assigned'"));
assert(! str_contains($source, "!== 'all'"));

echo "current report project access mode contract: OK\n";
