<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Access;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationSubject;

interface ReportAuthorizationSubjectReader
{
    public function run(string $runId): ReportAuthorizationSubject;

    public function export(string $exportId): ReportAuthorizationSubject;
}
