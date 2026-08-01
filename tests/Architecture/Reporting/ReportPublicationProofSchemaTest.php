<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationProof;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use InvalidArgumentException;
use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\TestCase;

final class ReportPublicationProofSchemaTest extends TestCase
{
    public function test_valid_proof_matches_strict_draft_2020_12_schema(): void
    {
        self::assertSame('https://json-schema.org/draft/2020-12/schema', $this->schema()->{'$schema'});
        self::assertTrue($this->validator()->validate($this->fixture(), $this->schema())->isValid());
    }

    public function test_unknown_root_and_nested_properties_fail_closed(): void
    {
        $root = $this->fixture();
        $root->approved = true;
        self::assertFalse($this->validator()->validate($root, $this->schema())->isValid());

        $nested = $this->fixture();
        $nested->ci->provider = 'external';
        self::assertFalse($this->validator()->validate($nested, $this->schema())->isValid());
    }

    public function test_non_canonical_hash_failed_assertion_and_manage_permission_fail_closed(): void
    {
        $hash = $this->fixture();
        $hash->binding_sha256 = str_repeat('A', 64);
        self::assertFalse($this->validator()->validate($hash, $this->schema())->isValid());

        $assertion = $this->fixture();
        $assertion->source->assertion_codes[0] = 'source.identity.failed';
        self::assertFalse($this->validator()->validate($assertion, $this->schema())->isValid());

        $permission = $this->fixture();
        $permission->permissions->run = ['reports.manage'];
        self::assertFalse($this->validator()->validate($permission, $this->schema())->isValid());
    }

    public function test_export_assertions_are_bound_to_the_declared_format(): void
    {
        $proof = $this->fixture();
        $proof->export_contracts[0]->assertion_codes[0] = 'export.csv.fixture.passed';

        self::assertFalse($this->validator()->validate($proof, $this->schema())->isValid());
    }

    public function test_schema_and_canonical_dto_form_one_fail_closed_admission_contract(): void
    {
        self::assertTrue($this->canonicalProofIsValid($this->fixture()));

        $duplicateClass = $this->fixture();
        $component = clone $duplicateClass->components[0];
        $component->sha256 = str_repeat('f', 64);
        $duplicateClass->components[] = $component;
        self::assertFalse($this->canonicalProofIsValid($duplicateClass));

        $unsorted = $this->fixture();
        $earlierComponent = clone $unsorted->components[0];
        $earlierComponent->class = 'App\\AComponent';
        $earlierComponent->sha256 = str_repeat('c', 64);
        $unsorted->components[] = $earlierComponent;
        self::assertFalse($this->canonicalProofIsValid($unsorted));

        $hyphenatedManage = $this->fixture();
        $hyphenatedManage->permissions->run = ['reports-manage'];
        self::assertFalse($this->validator()->validate($hyphenatedManage, $this->schema())->isValid());
        self::assertFalse($this->canonicalProofIsValid($hyphenatedManage));

        $impossibleDate = $this->fixture();
        $impossibleDate->ci->completed_at_utc = '2026-02-31T01:02:03.123456Z';
        self::assertFalse($this->validator()->validate($impossibleDate, $this->schema())->isValid());
        self::assertFalse($this->canonicalProofIsValid($impossibleDate));
    }

    private function validator(): Draft202012SchemaValidator
    {
        return new Draft202012SchemaValidator(new CompliantValidator);
    }

    private function fixture(): object
    {
        return $this->json(dirname(__DIR__, 2).'/Fixtures/Reporting/Publication/report-publication-proof.valid.json');
    }

    private function schema(): object
    {
        return $this->json(dirname(__DIR__, 3).'/docs/reports/contracts/report-publication-proof.v1.schema.json');
    }

    private function json(string $path): object
    {
        return json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
    }

    private function canonicalProofIsValid(object $proof): bool
    {
        if (! $this->validator()->validate($proof, $this->schema())->isValid()) {
            return false;
        }

        try {
            ReportPublicationProof::fromArray(
                json_decode(json_encode($proof, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
            );
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }
}
