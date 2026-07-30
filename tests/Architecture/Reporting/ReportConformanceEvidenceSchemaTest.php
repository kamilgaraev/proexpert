<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\TestCase;

final class ReportConformanceEvidenceSchemaTest extends TestCase
{
    public function test_valid_evidence_matches_strict_draft_2020_12_schema(): void
    {
        self::assertSame(
            'https://json-schema.org/draft/2020-12/schema',
            $this->schema()->{'$schema'},
        );
        $payload = json_decode(
            json_encode($this->validEvidence(), JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $digest = $payload['digest'];
        unset($payload['digest']);
        self::assertSame($digest, hash('sha256', CanonicalJson::encode($payload)));
        self::assertTrue(
            $this->validator()->validate($this->validEvidence(), $this->schema())->isValid(),
        );
    }

    public function test_evidence_with_raw_filters_fails_schema(): void
    {
        $document = $this->validEvidence();
        $document->filters = ['project_id' => 1];

        self::assertFalse($this->validator()->validate($document, $this->schema())->isValid());
    }

    public function test_nested_unknown_property_fails_schema(): void
    {
        $document = $this->validEvidence();
        $document->source->unexpected = true;

        self::assertFalse($this->validator()->validate($document, $this->schema())->isValid());
        self::assertObjectNotHasProperty('unexpected', $this->validEvidence()->source);
    }

    public function test_sensitive_and_raw_url_keys_fail_schema(): void
    {
        foreach (['url', 'pii', 'rows', 'query'] as $key) {
            $document = $this->validEvidence();
            $document->formula->{$key} = 'forbidden';

            self::assertFalse(
                $this->validator()->validate($document, $this->schema())->isValid(),
                $key,
            );
        }

        $document = $this->validEvidence();
        $document->source->assertion_codes[0] = 'formula.availability.passed';
        self::assertFalse($this->validator()->validate($document, $this->schema())->isValid());

        $document = $this->validEvidence();
        $document->formula->assertion_codes[0] = 'source.totals.passed';
        self::assertFalse($this->validator()->validate($document, $this->schema())->isValid());

        $document = $this->validEvidence();
        $document->source->passed = false;
        self::assertFalse($this->validator()->validate($document, $this->schema())->isValid());

        $document = $this->validEvidence();
        $document->source->assertion_codes[0] = 'source.availability.failed';
        self::assertFalse($this->validator()->validate($document, $this->schema())->isValid());
    }

    private function validator(): Draft202012SchemaValidator
    {
        return new Draft202012SchemaValidator(new CompliantValidator);
    }

    private function validEvidence(): object
    {
        return $this->jsonObject(
            dirname(__DIR__, 2).'/Fixtures/Reporting/Conformance/report-conformance-evidence.valid.json',
        );
    }

    private function schema(): object
    {
        return $this->jsonObject(
            dirname(__DIR__, 3).'/docs/reports/contracts/report-conformance-evidence.schema.json',
        );
    }

    private function jsonObject(string $path): object
    {
        return json_decode(
            (string) file_get_contents($path),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
