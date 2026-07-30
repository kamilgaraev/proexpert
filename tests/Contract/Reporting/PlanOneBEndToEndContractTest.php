<?php

declare(strict_types=1);

namespace Tests\Contract\Reporting;

use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneACompletionRef;
use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneBEvidenceBuilder;
use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneBEvidenceValidator;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use Opis\JsonSchema\Validator as JsonSchemaValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PlanOneBEndToEndContractTest extends TestCase
{
    public function test_fixture_and_real_artifact_round_trip_pass_both_validators(): void
    {
        $fixture = $this->decode($this->root().'/tests/Fixtures/Reporting/plan-1b-completion.valid.json');
        $reference = $this->reference($fixture);
        $runtimeValidator = new PlanOneBEvidenceValidator($reference);

        $this->assertSchemaValid($fixture);
        $runtimeValidator->validate($fixture);

        $directory = sys_get_temp_dir().'/most-plan1b-contract-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $artifact = $directory.'/plan-1b-completion.json';

        try {
            $gateArtifacts = [];
            foreach ($fixture['gates'] as $gate) {
                $gatePath = $directory.'/'.$gate['id'].'.json';
                $envelope = [
                    'schema_version' => $fixture['schema_version'],
                    'artifact_id' => $gate['artifacts'][0]['id'],
                    'artifact_type' => $gate['artifacts'][0]['type'],
                    'repository_revision' => $fixture['repository_revision'],
                    'gate' => [
                        'id' => $gate['id'],
                        'status' => $gate['status'],
                        'command' => $gate['command'],
                        'result' => $gate['result'],
                        'duration_ms' => $gate['duration_ms'],
                        'measurements' => $gate['measurements'],
                    ],
                ];
                $bytes = CanonicalJson::encode($envelope)."\n";
                file_put_contents($gatePath, $bytes);
                $gateArtifacts[] = [
                    'path' => $gatePath,
                    'sha256' => hash('sha256', $bytes),
                ];
            }

            $built = (new PlanOneBEvidenceBuilder($artifact))->build(
                $reference,
                [
                    'repository_revision' => $fixture['repository_revision'],
                    'gate_artifacts' => $gateArtifacts,
                    'ownership' => $fixture['ownership'],
                    'unresolved_risks' => $fixture['unresolved_risks'],
                ],
                new DateTimeImmutable($fixture['generated_at']),
            );

            self::assertSame('ci', $built['evidence_scope']);
            $runtimeValidator->validate($built);
            $this->assertSchemaValid($built);
            self::assertSame(CanonicalJson::encode($built)."\n", (string) file_get_contents($artifact));
            foreach ($built['gates'] as $index => $gate) {
                self::assertSame($gateArtifacts[$index]['sha256'], $gate['artifacts'][0]['sha256']);
            }
        } finally {
            foreach (glob($directory.'/*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
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

    private function assertSchemaValid(array $document): void
    {
        $schema = json_decode(
            (string) file_get_contents($this->root().'/docs/reports/contracts/plan-1b-evidence.schema.json'),
        );
        $result = (new JsonSchemaValidator)->validate(
            json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR),
            $schema,
        );

        self::assertTrue($result->isValid(), $result->error()?->message() ?? 'JSON Schema validation failed');
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
