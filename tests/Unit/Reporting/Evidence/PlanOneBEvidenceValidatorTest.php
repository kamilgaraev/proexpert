<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Evidence;

use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneACompletionRef;
use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneBEvidenceValidator;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneBEvidenceValidator.php';

final class PlanOneBEvidenceValidatorTest extends TestCase
{
    public function test_accepts_the_tracked_deterministic_fixture_with_stable_canonical_digest(): void
    {
        $document = $this->fixture();

        $this->validator($document)->validate($document);

        self::assertSame(
            '31017bd4d8007c9881243f3a0c97919a96fb6b55f39e844e530a3459de24179e',
            hash('sha256', CanonicalJson::encode($document)),
        );
    }

    public function test_rejects_missing_unknown_and_wrongly_typed_root_members(): void
    {
        $mutations = [
            'missing root member' => static function (array &$document): void {
                unset($document['repository_revision']);
            },
            'unknown root member' => static function (array &$document): void {
                $document['telemetry_mode'] = false;
            },
            'wrong scalar type' => static function (array &$document): void {
                $document['schema_version'] = 1;
            },
        ];

        $this->assertMutationsRejected($mutations);
    }

    public function test_rejects_non_iso_timestamps_and_noncanonical_digests(): void
    {
        $mutations = [
            'non ISO generation timestamp' => static function (array &$document): void {
                $document['generated_at'] = '2026-07-30 12:00:00';
            },
            'impossible timestamp' => static function (array &$document): void {
                $document['generated_at'] = '2026-02-30T12:00:00Z';
            },
            'uppercase digest' => static function (array &$document): void {
                $document['gates'][1]['artifacts'][0]['sha256'] = str_repeat('A', 64);
            },
            'short digest' => static function (array &$document): void {
                $document['gates'][1]['artifacts'][0]['sha256'] = str_repeat('a', 63);
            },
            'wrong revision type' => static function (array &$document): void {
                $document['repository_revision'] = 123;
            },
        ];

        $this->assertMutationsRejected($mutations);
    }

    public function test_rejects_a_plan_one_a_lock_digest_different_from_the_verified_reference(): void
    {
        $document = $this->fixture();
        $validator = $this->validator($document);
        $document['plan_1a_reference']['lock_sha256'] = str_repeat('f', 64);

        $this->assertRejected(static fn () => $validator->validate($document));
    }

    public function test_rejects_missing_duplicate_unknown_or_failed_required_gates(): void
    {
        $mutations = [
            'missing required gate' => static function (array &$document): void {
                array_pop($document['gates']);
            },
            'duplicate gate ID' => static function (array &$document): void {
                $document['gates'][1]['id'] = $document['gates'][0]['id'];
            },
            'unknown gate ID' => static function (array &$document): void {
                $document['gates'][1]['id'] = 'browser_smoke';
            },
            'failed required gate' => static function (array &$document): void {
                $document['gates'][1]['status'] = 'failed';
            },
        ];

        $this->assertMutationsRejected($mutations);
    }

    public function test_rejects_missing_command_result_duration_and_artifact_digest(): void
    {
        foreach (['command', 'result', 'duration_ms', 'artifacts'] as $member) {
            $document = $this->fixture();
            unset($document['gates'][1][$member]);
            $this->assertRejected(
                fn () => $this->validator($document)->validate($document),
                $member,
            );
        }

        $document = $this->fixture();
        unset($document['gates'][1]['artifacts'][0]['sha256']);
        $this->assertRejected(fn () => $this->validator($document)->validate($document));
    }

    public function test_rejects_forbidden_runtime_browser_and_build_evidence(): void
    {
        foreach (['runtime verification', 'browser smoke', 'npm run build'] as $command) {
            $document = $this->fixture();
            $document['gates'][1]['command'] = $command;
            $this->assertRejected(
                fn () => $this->validator($document)->validate($document),
                $command,
            );
        }
    }

    public function test_rejects_plan_one_a_symbols_claimed_by_plan_one_b(): void
    {
        $document = $this->fixture();
        $document['ownership']['plan_1b_symbols'][0] = 'ReportRun';

        $this->assertRejected(fn () => $this->validator($document)->validate($document));
    }

    public function test_rejects_subscription_telemetry_at_any_nested_value(): void
    {
        $document = $this->fixture();
        $document['unresolved_risks'][] = 'subscription telemetry is pending';

        $this->assertRejected(fn () => $this->validator($document)->validate($document));
    }

    public function test_rejects_duplicate_artifact_names_and_invalid_performance_limits(): void
    {
        $mutations = [
            'duplicate artifact names' => static function (array &$document): void {
                $document['gates'][1]['artifacts'][] = $document['gates'][1]['artifacts'][0];
            },
            'measurement over limit' => static function (array &$document): void {
                $document['performance_measurements'][0]['value'] = 5001;
            },
            'duplicate measurement ID' => static function (array &$document): void {
                $document['performance_measurements'][1]['id'] = $document['performance_measurements'][0]['id'];
            },
        ];

        $this->assertMutationsRejected($mutations);
    }

    private function assertMutationsRejected(array $mutations): void
    {
        foreach ($mutations as $label => $mutation) {
            $document = $this->fixture();
            $mutation($document);
            $this->assertRejected(
                fn () => $this->validator($document)->validate($document),
                $label,
            );
        }
    }

    private function assertRejected(callable $operation, string $label = ''): void
    {
        try {
            $operation();
            self::fail('Expected rejection: '.$label);
        } catch (InvalidArgumentException $exception) {
            self::assertSame('plan_one_b_evidence_invalid', $exception->getMessage(), $label);
        }
    }

    private function validator(array $document): PlanOneBEvidenceValidator
    {
        $reference = $document['plan_1a_reference'];

        return new PlanOneBEvidenceValidator(new PlanOneACompletionRef(
            $reference['lock_sha256'],
            $reference['evidence_sha256'],
            new DateTimeImmutable($reference['generated_at']),
            $reference['status'],
        ));
    }

    private function fixture(): array
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/Reporting/plan-1b-completion.valid.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
