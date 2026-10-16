<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Http;

use App\BusinessModules\Core\Reporting\Http\Admin\Requests\CreateReportSubscriptionRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\UpdateReportSubscriptionRequest;
use Illuminate\Routing\Route;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UpdateReportSubscriptionRequestTest extends TestCase
{
    #[DataProvider('projectUpdates')]
    public function test_nullable_project_is_preserved_and_omission_remains_absent(array $body, array $expected): void
    {
        [$request, $validator] = $this->request($body);
        self::assertTrue($validator->passes());
        $request->setValidator($validator);
        self::assertSame($expected, $request->toData()->changes);
    }

    public static function projectUpdates(): array
    {
        return [
            [['project_id' => null], ['project_id' => null]],
            [['project_id' => 52], ['project_id' => 52]],
            [['project_id' => '52'], ['project_id' => 52]],
            [['format' => 'csv'], ['format' => 'csv']],
        ];
    }

    #[DataProvider('invalidProjects')]
    public function test_invalid_project_is_rejected(mixed $projectId): void
    {
        [, $validator] = $this->request(['project_id' => $projectId]);
        self::assertFalse($validator->passes());
        self::assertTrue($validator->errors()->has('project_id'));
    }

    public static function invalidProjects(): array
    {
        return [[0], [-1], [true], [false], ['all'], [[]]];
    }

    public function test_creation_does_not_coerce_boolean_into_project_one(): void
    {
        $request = CreateReportSubscriptionRequest::create('/', 'POST', [
            'saved_view_id' => '01J00000000000000000000001', 'frequency' => 'daily',
            'weekday' => null, 'day_of_month' => null, 'local_time' => '09:00', 'timezone' => 'Europe/Moscow',
            'period_policy' => ['mode' => 'fixed_filters'], 'format' => 'xlsx', 'project_id' => true,
        ]);
        $validator = (new Factory(new Translator(new ArrayLoader, 'ru')))->make($request->all(), $request->rules());
        self::assertFalse($validator->passes());
        self::assertTrue($validator->errors()->has('project_id'));
    }

    private function request(array $body): array
    {
        $request = UpdateReportSubscriptionRequest::create('/reports/subscriptions/01J00000000000000000000001', 'PATCH', $body);
        $route = new Route(['PATCH'], 'reports/subscriptions/{subscriptionId}', static fn () => null);
        $route->bind($request);
        $request->setRouteResolver(static fn () => $route);
        $validator = (new Factory(new Translator(new ArrayLoader, 'ru')))->make($request->validationData(), $request->rules());
        foreach ($request->after() as $after) {
            $validator->after($after);
        }

        return [$request, $validator];
    }
}
