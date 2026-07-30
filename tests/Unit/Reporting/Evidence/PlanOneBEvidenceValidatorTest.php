<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Evidence;

use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneACompletionRef;
use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneBEvidenceValidator;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use InvalidArgumentException;
use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlanOneBEvidenceValidatorTest extends TestCase
{
    public function test_fixture_is_explicitly_synthetic_and_passes_both_executable_validators(): void
    {
        $document = $this->fixture();

        self::assertSame('fixture', $document['evidence_scope']);
        self::assertTrue($this->schemaAccepts($document));
        $this->validator($document)->validate($document);
        self::assertSame(
            '507022367f5609ac901800dc0c419edadef8e4ba201c7b78c4fb1ad064045f88',
            hash('sha256', CanonicalJson::encode($document)),
        );
    }

    #[DataProvider('sharedMutationCorpus')]
    public function test_schema_and_php_validator_reject_the_same_json_representable_mutations(
        string $label,
        callable $mutation,
    ): void {
        $document = $this->fixture();
        $mutation($document);

        self::assertFalse($this->schemaAccepts($document), $label.' schema');
        $this->assertRejected(fn () => $this->validator($document)->validate($document), $label.' PHP');
    }

    public static function sharedMutationCorpus(): array
    {
        return [
            'missing root property' => ['missing root property', static function (array &$document): void {
                unset($document['repository_revision']);
            }],
            'unknown root property' => ['unknown root property', static function (array &$document): void {
                $document['unknown'] = true;
            }],
            'wrong scalar type' => ['wrong scalar type', static function (array &$document): void {
                $document['schema_version'] = 1;
            }],
            'duplicate gate' => ['duplicate gate', static function (array &$document): void {
                $document['gates'][1] = $document['gates'][0];
            }],
            'permuted gates' => ['permuted gates', static function (array &$document): void {
                [$document['gates'][0], $document['gates'][1]] = [$document['gates'][1], $document['gates'][0]];
            }],
            'extra gate' => ['extra gate', static function (array &$document): void {
                $document['gates'][] = $document['gates'][0];
            }],
            'missing gate' => ['missing gate', static function (array &$document): void {
                array_pop($document['gates']);
            }],
            'arbitrary command' => ['arbitrary command', static function (array &$document): void {
                $document['gates'][2]['command'] = 'vendor/bin/phpunit';
            }],
            'wrong artifact ID' => ['wrong artifact ID', static function (array &$document): void {
                $document['gates'][2]['artifacts'][0]['id'] = 'plan1b.gate.other';
            }],
            'wrong artifact type' => ['wrong artifact type', static function (array &$document): void {
                $document['gates'][2]['artifacts'][0]['type'] = 's3_json';
            }],
            'extra artifact' => ['extra artifact', static function (array &$document): void {
                $document['gates'][2]['artifacts'][] = $document['gates'][2]['artifacts'][0];
            }],
            'missing artifact' => ['missing artifact', static function (array &$document): void {
                $document['gates'][2]['artifacts'] = [];
            }],
            'wrong required results' => ['wrong required results', static function (array &$document): void {
                array_pop($document['gates'][4]['result']['required_checks']);
            }],
            'unknown result member' => ['unknown result member', static function (array &$document): void {
                $document['gates'][4]['result']['unknown'] = true;
            }],
            'arbitrary ownership' => ['arbitrary ownership', static function (array &$document): void {
                $document['ownership']['plan_1b_symbols'][0] = 'ReportRun';
            }],
            'permuted performance' => ['permuted performance', static function (array &$document): void {
                [$document['performance_measurements'][0], $document['performance_measurements'][1]]
                    = [$document['performance_measurements'][1], $document['performance_measurements'][0]];
            }],
            'missing gate measurement' => ['missing gate measurement', static function (array &$document): void {
                array_pop($document['gates'][18]['measurements']);
            }],
            'wrong measurement ID' => ['wrong measurement ID', static function (array &$document): void {
                $document['gates'][18]['measurements'][0]['id'] = 'arbitrary';
            }],
            'row limit exceeded' => ['row limit exceeded', static function (array &$document): void {
                $document['gates'][18]['measurements'][0]['value'] = 5001;
            }],
            'value exceeds exact limit' => ['value exceeds exact limit', static function (array &$document): void {
                $document['gates'][18]['measurements'][1]['value'] = 21;
            }],
            'arbitrary measurement limit' => ['arbitrary measurement limit', static function (array &$document): void {
                $document['gates'][18]['measurements'][1]['limit'] = 21;
            }],
            'noncanonical digest' => ['noncanonical digest', static function (array &$document): void {
                $document['gates'][2]['artifacts'][0]['sha256'] = str_repeat('A', 64);
            }],
            'forbidden evidence family' => ['forbidden evidence family', static function (array &$document): void {
                $document['unresolved_risks'][] = 'subscription telemetry';
            }],
        ];
    }

    public function test_php_validator_additionally_binds_cross_field_references(): void
    {
        $mutations = [
            static function (array &$document): void {
                $document['plan_1a_reference']['lock_sha256'] = str_repeat('f', 64);
            },
            static function (array &$document): void {
                $document['gates'][2]['artifacts'][0]['repository_revision'] = str_repeat('f', 40);
            },
            static function (array &$document): void {
                $document['performance_measurements'][0]['value'] = 4999;
            },
        ];

        foreach ($mutations as $mutation) {
            $document = $this->fixture();
            $validator = $this->validator($document);
            $mutation($document);
            $this->assertRejected(static fn () => $validator->validate($document));
        }
    }

    private function schemaAccepts(array $document): bool
    {
        $data = json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $schema = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4).'/docs/reports/contracts/plan-1b-evidence.schema.json'),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );

        return (new CompliantValidator)->validate($data, $schema)->isValid();
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
