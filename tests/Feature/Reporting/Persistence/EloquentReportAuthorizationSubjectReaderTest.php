<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Persistence;

use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportAuthorizationSubjectReader;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportAuthorizationSubjectReader;
use PHPUnit\Framework\TestCase;

final class EloquentReportAuthorizationSubjectReaderTest extends TestCase
{
    public function test_reader_is_the_closed_persistence_adapter_for_authorization_subjects(): void
    {
        self::assertTrue(is_subclass_of(EloquentReportAuthorizationSubjectReader::class, ReportAuthorizationSubjectReader::class));
    }
}
