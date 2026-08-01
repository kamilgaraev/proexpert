<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\TestCase;

final class ReportQualityEvidenceSchemaTest extends TestCase
{
    public function test_quality_fixtures_are_valid_against_the_closed_schemas(): void
    {
        $root = dirname(__DIR__, 3);
        $qualitySchema = $this->decode($root.'/docs/reports/contracts/report-quality-evidence.schema.json');
        $releaseBundleSchema = $this->decode($root.'/docs/reports/contracts/report-release-gate-bundle.schema.json');
        $validator = new CompliantValidator();

        foreach ([
            [$root.'/tests/Fixtures/Reporting/Quality/platform-gates.valid.json', $qualitySchema],
            [$root.'/tests/Fixtures/Reporting/Quality/report-platform-evidence.valid.json', $qualitySchema],
            [$root.'/tests/Fixtures/Reporting/Quality/report-release-gate-bundle.valid.json', $releaseBundleSchema],
        ] as [$fixturePath, $schema]) {
            self::assertTrue($validator->validate($this->decode($fixturePath), $schema)->isValid(), $fixturePath);
        }
    }

    private function decode(string $path): object
    {
        $document = json_decode((string) file_get_contents($path));

        self::assertIsObject($document, $path);

        return $document;
    }
}
