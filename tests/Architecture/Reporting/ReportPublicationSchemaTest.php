<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\TestCase;

final class ReportPublicationSchemaTest extends TestCase
{
    public function test_tracked_lock_and_candidate_validation_match_strict_schemas(): void
    {
        self::assertTrue($this->valid('report-publication-lock.valid.json', 'report-publication-lock.schema.json'));
        self::assertTrue($this->valid('candidate-validation.valid.json', 'report-candidate-validation.schema.json'));
    }

    public function test_unknown_lock_and_validation_members_fail_closed(): void
    {
        $lock = $this->fixture('report-publication-lock.valid.json');
        $lock->unexpected = true;
        self::assertFalse($this->validator()->validate(
            $lock,
            $this->schema('report-publication-lock.schema.json'),
        )->isValid());

        $validation = $this->fixture('candidate-validation.valid.json');
        $validation->items[0]->unexpected = true;
        self::assertFalse($this->validator()->validate(
            $validation,
            $this->schema('report-candidate-validation.schema.json'),
        )->isValid());
    }

    public function test_ledger_rejects_unknown_event_enum_and_conflicting_shape(): void
    {
        $lock = $this->fixture('report-publication-lock.valid.json');
        $ledger = json_decode(json_encode([
            'artifact_id' => 'report_publication_ledger',
            'schema_version' => '1.0.0',
            'events' => [[
                'event_id' => 'reports:definition:project_portfolio_health:published:'.str_repeat('a', 64),
                'event_type' => 'definition_published',
                'lock_digest' => str_repeat('b', 64),
                'lock' => $lock,
            ]],
        ], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $schema = $this->schema('report-publication-ledger.schema.json');

        self::assertTrue($this->validator()->validate($ledger, $schema)->isValid());
        $ledger->events[0]->event_type = 'definition_replaced';
        self::assertFalse($this->validator()->validate($ledger, $schema)->isValid());
    }

    private function valid(string $fixture, string $schema): bool
    {
        return $this->validator()->validate($this->fixture($fixture), $this->schema($schema))->isValid();
    }

    private function validator(): Draft202012SchemaValidator
    {
        return new Draft202012SchemaValidator(new CompliantValidator);
    }

    private function fixture(string $file): object
    {
        return $this->json(dirname(__DIR__, 2).'/Fixtures/Reporting/Publication/'.$file);
    }

    private function schema(string $file): object
    {
        return $this->json(dirname(__DIR__, 3).'/docs/reports/contracts/'.$file);
    }

    private function json(string $path): object
    {
        return json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
    }
}
