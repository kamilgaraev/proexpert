<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Services\LegalArchive\Integrations\LegalDocumentReconciliationService;
use PHPUnit\Framework\TestCase;

final class LegalDocumentReconciliationArchitectureTest extends TestCase
{
    public function test_all_required_sources_are_declared(): void
    {
        self::assertSame(['contracts', 'supplementary_agreements', 'acts', 'commercial_proposals', 'procurement', 'payments', 'executive_documentation'], LegalDocumentReconciliationService::SOURCES);
    }

    public function test_direct_contract_create_is_limited_to_the_canonical_boundary(): void
    {
        $root = dirname(__DIR__, 3).'/app';
        $violations = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') continue;
            $path = $file->getPathname();
            if (str_contains($path, 'Services/Contract/ContractSideMutationService.php') || str_contains($path, 'Factories')) continue;
            if (str_contains((string) file_get_contents($path), 'Contract::create(')) $violations[] = $path;
        }
        self::assertSame([], $violations);
    }
}
