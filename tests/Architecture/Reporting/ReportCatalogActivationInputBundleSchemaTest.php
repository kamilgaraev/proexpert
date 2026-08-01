<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\TestCase;

final class ReportCatalogActivationInputBundleSchemaTest extends TestCase
{
    public function test_fixture_matches_the_closed_schema(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertTrue((new CompliantValidator())->validate($this->json($root.'/tests/Fixtures/Reporting/Activation/report-catalog-activation-input-bundle.valid.json'), $this->json($root.'/docs/reports/contracts/report-catalog-activation-input-bundle.schema.json'))->isValid());
    }

    private function json(string $path): object
    {
        return json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
    }
}

