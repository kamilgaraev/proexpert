<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
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
}
