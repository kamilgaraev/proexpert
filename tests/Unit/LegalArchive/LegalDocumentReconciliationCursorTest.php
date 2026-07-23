<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposal;
use App\Models\Contract;
use App\Services\LegalArchive\Integrations\LegalDocumentReconciliationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class LegalDocumentReconciliationCursorTest extends TestCase
{
    public function test_integer_cursor_is_used_only_for_incrementing_integer_sources(): void
    {
        $service = (new ReflectionClass(LegalDocumentReconciliationService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'usesIntegerCursor');

        self::assertTrue($method->invoke($service, new Contract()));
        self::assertFalse($method->invoke($service, new CommercialProposal()));
    }
}
