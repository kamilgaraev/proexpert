<?php

declare(strict_types=1);

namespace Tests\Contract\Reporting;

use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneACompletionRef;
use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneBEvidenceBuilder;
use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneBEvidenceValidator;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 3).'/app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneBEvidenceValidator.php';
require_once dirname(__DIR__, 3).'/app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneBEvidenceBuilder.php';

final class PlanOneBEndToEndContractTest extends TestCase
{
    public function test_fixture_passes_schema_executable_validator_and_builder_round_trip(): void
    {
        $fixturePath = $this->root().'/tests/Fixtures/Reporting/plan-1b-completion.valid.json';
        $fixtureBytes = (string) file_get_contents($fixturePath);
        $fixture = json_decode($fixtureBytes, true, 512, JSON_THROW_ON_ERROR);
        $schema = json_decode(
            (string) file_get_contents($this->root().'/docs/reports/contracts/plan-1b-evidence.schema.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $reference = $this->reference($fixture);

        self::assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        self::assertSame('urn:most:reporting:plan-1b-evidence:v1', $schema['$id']);
        self::assertFalse($schema['additionalProperties']);
        (new PlanOneBEvidenceValidator($reference))->validate($fixture);

        $directory = sys_get_temp_dir().'/most-plan1b-contract-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $artifact = $directory.'/plan-1b-completion.json';
        try {
            $built = (new PlanOneBEvidenceBuilder($artifact))->build(
                $reference,
                [
                    'repository_revision' => $fixture['repository_revision'],
                    'gates' => $fixture['gates'],
                    'ownership' => $fixture['ownership'],
                    'performance_measurements' => $fixture['performance_measurements'],
                    'unresolved_risks' => $fixture['unresolved_risks'],
                ],
                new DateTimeImmutable($fixture['generated_at']),
            );
            self::assertEquals($fixture, $built);
            self::assertEquals($fixture, $this->decode($artifact));
        } finally {
            if (is_file($artifact)) {
                unlink($artifact);
            }
            rmdir($directory);
        }
    }

    public function test_handoff_ownership_and_post_ci_artifact_boundary_are_exact(): void
    {
        $fixture = $this->decode($this->root().'/tests/Fixtures/Reporting/plan-1b-completion.valid.json');

        self::assertSame([
            'plans_2_and_3' => 'plan_1a_provider_ports_candidate_bindings_only',
            'plan_1c' => 'published_registry_map_and_all_publication_transitions',
            'plan_4' => 'evidence_verification_and_deployment_rollout_only',
            'artifact_path' => 'build/reports/plan-1b-completion.json',
            'digest_algorithm' => 'sha256',
        ], $fixture['handoff']);
        self::assertSame([
            'plan_1c_publication_registry',
            'plan_2_candidate_provider_bindings',
            'plan_3_candidate_provider_bindings',
            'plan_4_evidence_verification_rollout',
        ], $fixture['ownership']['external_plan_owners']);

        $ignored = new Process(
            ['git', 'check-ignore', '-q', 'build/reports/plan-1b-completion.json'],
            $this->root(),
        );
        $ignored->run();
        self::assertSame(0, $ignored->getExitCode());

        $tracked = new Process(
            ['git', 'ls-files', '--error-unmatch', 'build/reports/plan-1b-completion.json'],
            $this->root(),
        );
        $tracked->run();
        self::assertNotSame(0, $tracked->getExitCode());
        self::assertFileDoesNotExist($this->root().'/build/reports/plan-1b-completion.json');
    }

    private function reference(array $fixture): PlanOneACompletionRef
    {
        $reference = $fixture['plan_1a_reference'];

        return new PlanOneACompletionRef(
            $reference['lock_sha256'],
            $reference['evidence_sha256'],
            new DateTimeImmutable($reference['generated_at']),
            $reference['status'],
        );
    }

    private function decode(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
