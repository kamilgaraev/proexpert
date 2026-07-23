<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Models\Contract;
use App\Services\LegalArchive\Integrations\LegalDocumentReconciliationService;
use ErrorException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class LegalDocumentReconciliationProjectIdTest extends TestCase
{
    public function test_contract_without_project_id_does_not_require_contract_relation(): void
    {
        $service = (new ReflectionClass(LegalDocumentReconciliationService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($service, 'projectId');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            self::assertNull($method->invoke($service, new Contract([
                'id' => 10,
                'organization_id' => 14,
            ])));
        } finally {
            restore_error_handler();
        }
    }
}
