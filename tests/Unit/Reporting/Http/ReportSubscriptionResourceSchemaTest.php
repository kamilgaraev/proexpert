<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Http;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscription;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionDelivery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionExecutionInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionDeliveryStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionFrequency;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionTrigger;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Http\Admin\Resources\ReportSubscriptionDeliveryResource;
use App\BusinessModules\Core\Reporting\Http\Admin\Resources\ReportSubscriptionPageResource;
use App\BusinessModules\Core\Reporting\Http\Admin\Resources\ReportSubscriptionResource;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportSubscriptionResourceSchemaTest extends TestCase
{
    #[DataProvider('resourceBranches')]
    public function test_resource_serializes_exact_fixture_data(string $branch): void
    {
        self::assertFileExists($this->fixturePath());

        self::assertSame(
            $this->fixtures()[$branch]['data'],
            $this->resource($branch)->toArray(Request::create('/')),
        );
    }

    public static function resourceBranches(): array
    {
        return [['subscription'], ['delivery'], ['page']];
    }

    public function test_all_subscription_resource_envelopes_match_draft_2020_12_schema(): void
    {
        self::assertFileExists($this->schemaPath());
        self::assertFileExists($this->fixturePath());

        foreach (['subscription', 'delivery', 'page'] as $branch) {
            self::assertTrue(
                $this->validator()->validate($this->fixtureObject($branch), $this->schema())->isValid(),
                $branch,
            );
        }
    }

    public function test_schema_rejects_missing_extra_and_wrong_type_fields(): void
    {
        $missing = $this->fixtureObject('subscription');
        unset($missing->data->id);
        self::assertFalse($this->validator()->validate($missing, $this->schema())->isValid());

        $extra = $this->fixtureObject('page');
        $extra->data->meta->total = 1;
        self::assertFalse($this->validator()->validate($extra, $this->schema())->isValid());

        $wrongType = $this->fixtureObject('delivery');
        $wrongType->data->attempt = '1';
        self::assertFalse($this->validator()->validate($wrongType, $this->schema())->isValid());
    }

    public function test_resources_keep_legacy_organization_scope_nullable(): void
    {
        self::assertNull((new ReportSubscriptionResource($this->subscription(null)))->toArray(Request::create('/'))['project_id']);
        self::assertNull((new ReportSubscriptionDeliveryResource($this->delivery(null)))->toArray(Request::create('/'))['project_id']);
    }

    #[DataProvider('invalidProjectIds')]
    public function test_schema_rejects_invalid_frozen_project(mixed $projectId): void
    {
        foreach (['subscription', 'delivery'] as $branch) {
            $value = $this->fixtureObject($branch);
            $value->data->project_id = $projectId;
            self::assertFalse($this->validator()->validate($value, $this->schema())->isValid());
        }
    }

    public static function invalidProjectIds(): array
    {
        return [[0], [-1], ['52'], [true], [[]]];
    }

    #[DataProvider('legacyProjectFilters')]
    public function test_resources_preserve_effective_legacy_project(mixed $filter): void
    {
        self::assertSame(53, (new ReportSubscriptionResource($this->subscription(null, ['project_id' => $filter])))->toArray(Request::create('/'))['project_id']);
        self::assertSame(53, (new ReportSubscriptionDeliveryResource($this->delivery(null, ['project_id' => $filter])))->toArray(Request::create('/'))['project_id']);
        self::assertSame(52, (new ReportSubscriptionResource($this->subscription(52, ['project_id' => $filter])))->toArray(Request::create('/'))['project_id']);
    }

    public static function legacyProjectFilters(): array
    {
        return [[53], ['53'], [['operator' => 'eq', 'value' => 53]], [['operator' => 'eq', 'value' => '53']]];
    }

    private function resource(string $branch): ReportSubscriptionResource|ReportSubscriptionDeliveryResource|ReportSubscriptionPageResource
    {
        $subscription = $this->subscription();

        return match ($branch) {
            'subscription' => new ReportSubscriptionResource($subscription),
            'delivery' => new ReportSubscriptionDeliveryResource($this->delivery()),
            'page' => new ReportSubscriptionPageResource(new ReportSubscriptionPage([$subscription], null, 50, false)),
        };
    }

    private function subscription(?int $projectId = 52, array $filters = []): ReportSubscription
    {
        $input = $this->executionInput($projectId, $filters);

        return new ReportSubscription(
            '01J00000000000000000000001',
            10,
            20,
            '01J00000000000000000000002',
            'budget_plan_fact',
            ReportSubscriptionFrequency::DAILY,
            null,
            null,
            '10:00',
            new DateTimeZone('UTC'),
            ['mode' => 'previous_day'],
            'xlsx',
            ReportSubscriptionStatus::ACTIVE,
            null,
            0,
            new DateTimeImmutable('2026-07-27T10:00:00+00:00'),
            $input,
            new Sha256Hash(hash('sha256', $input)),
            new Sha256Hash(str_repeat('a', 64)),
            '1.0.0',
            1,
            new DateTimeImmutable('2026-07-26T09:00:00+00:00'),
            new DateTimeImmutable('2026-07-26T09:01:00+00:00'),
        );
    }

    private function delivery(?int $projectId = 52, array $filters = []): ReportSubscriptionDelivery
    {
        $input = $this->executionInput($projectId, $filters);

        return new ReportSubscriptionDelivery(
            '01J00000000000000000000003',
            10,
            20,
            '01J00000000000000000000001',
            ReportSubscriptionTrigger::MANUAL,
            null,
            null,
            new DateTimeImmutable('2026-07-26T10:00:00+00:00'),
            $input,
            new Sha256Hash(hash('sha256', $input)),
            1,
            ReportSubscriptionDeliveryStatus::READY,
            1,
            '01J00000000000000000000004',
            '01J00000000000000000000005',
            null,
            null,
            null,
            new DateTimeImmutable('2026-07-27T10:00:00+00:00'),
            new DateTimeImmutable('2026-10-26T10:00:00+00:00'),
            new DateTimeImmutable('2026-07-26T10:00:00+00:00'),
            new DateTimeImmutable('2026-07-26T10:01:00+00:00'),
        );
    }

    private function executionInput(?int $projectId, array $filters = []): string
    {
        return (new ReportSubscriptionExecutionInput(
            'budget_plan_fact', new ReportFilterSet($filters), [], 'ru', '01J00000000000000000000002',
            'xlsx', ['name'], new ReportWindowSort('name', ReportSortDirection::ASC), new DateTimeZone('UTC'),
            ['mode' => 'previous_day'], '1.0.0', new Sha256Hash(str_repeat('a', 64)),
            projectScopeId: $projectId,
        ))->canonicalBytes();
    }

    private function fixtures(): array
    {
        return json_decode((string) file_get_contents($this->fixturePath()), true, 512, JSON_THROW_ON_ERROR);
    }

    private function fixtureObject(string $branch): object
    {
        return json_decode(json_encode($this->fixtures()[$branch], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }

    private function validator(): Draft202012SchemaValidator
    {
        return new Draft202012SchemaValidator(new CompliantValidator);
    }

    private function schema(): object
    {
        return json_decode((string) file_get_contents($this->schemaPath()), false, 512, JSON_THROW_ON_ERROR);
    }

    private function fixturePath(): string
    {
        return dirname(__DIR__, 4).'/tests/Fixtures/Reporting/Wire/report-subscription-resources.v1.json';
    }

    private function schemaPath(): string
    {
        return dirname(__DIR__, 4).'/docs/reports/contracts/report-subscription-resources.v1.schema.json';
    }
}
