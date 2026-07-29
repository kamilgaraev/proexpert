<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Access;

use App\BusinessModules\Core\Reporting\Infrastructure\Access\LaravelCurrentReportAbacEvaluator;
use App\Domain\Authorization\Models\UserRoleAssignment;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class LaravelCurrentReportAbacEvaluatorTest extends TestCase
{
    public function test_queue_evaluator_has_no_ambient_request_or_authority_cache_dependency(): void
    {
        $source = file_get_contents((new \ReflectionClass(LaravelCurrentReportAbacEvaluator::class))->getFileName());
        self::assertIsString($source);
        foreach (['AuthorizationService', '->can(', 'request(', 'auth(', 'Cache::'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
        self::assertStringContainsString('UserRoleAssignment::query()', $source);
        self::assertStringContainsString('RoleCondition', $source);
        self::assertStringContainsString('projectCountPasses', $source);
    }

    #[DataProvider('malformedTimeConditions')]
    public function test_time_condition_fails_closed_for_empty_or_malformed_data(array $condition): void
    {
        $method = new ReflectionMethod(LaravelCurrentReportAbacEvaluator::class, 'timePasses');

        self::assertFalse($method->invoke(
            new LaravelCurrentReportAbacEvaluator,
            $condition,
            new DateTimeImmutable('2026-07-29T12:00:00+00:00'),
        ));
    }

    public function test_unknown_role_type_fails_closed_without_role_lookup(): void
    {
        $assignment = new UserRoleAssignment([
            'role_slug' => 'viewer',
            'role_type' => 'unexpected',
        ]);
        $method = new ReflectionMethod(LaravelCurrentReportAbacEvaluator::class, 'roleGrants');

        self::assertFalse($method->invoke(
            new LaravelCurrentReportAbacEvaluator,
            $assignment,
            'reports.view',
            1,
        ));
    }

    public static function malformedTimeConditions(): array
    {
        return [
            'empty' => [[]],
            'unknown key' => [['timezone' => 'UTC']],
            'empty boundary' => [['valid_from' => '']],
            'invalid boundary' => [['valid_until' => 'not-a-date']],
            'empty working days' => [['working_days' => []]],
            'invalid working day type' => [['working_days' => ['3']]],
            'invalid working day value' => [['working_days' => [7]]],
            'invalid working hour' => [['working_hours' => '29:00-30:00']],
            'reversed working hours' => [['working_hours' => '18:00-09:00']],
        ];
    }
}
