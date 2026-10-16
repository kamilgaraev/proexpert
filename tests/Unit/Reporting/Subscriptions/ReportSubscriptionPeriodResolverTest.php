<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Subscriptions;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionPeriodResolver;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionExecutionInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportSubscriptionPeriodResolverTest extends TestCase
{
    #[DataProvider('periods')]
    public function test_resolves_completed_local_period_and_preserves_scheduled_as_of(string $mode, string $at, string $timezone, string $from, string $to): void
    {
        $input = $this->input(['mode' => $mode], $timezone);
        $bytes = $input->canonicalBytes();
        $scheduledFor = new DateTimeImmutable($at);
        $data = (new ReportSubscriptionPeriodResolver)->runData($input, $scheduledFor);

        self::assertSame($from, $data->filters->values['period_from']);
        self::assertSame($to, $data->filters->values['period_to']);
        self::assertSame(52, $data->filters->values['project_id']);
        self::assertSame($scheduledFor, $data->asOf);
        self::assertSame($bytes, $input->canonicalBytes());
    }

    public static function periods(): array
    {
        return [
            ['previous_day', '2026-03-09T04:30:00Z', 'America/New_York', '2026-03-08', '2026-03-08'],
            ['previous_week', '2026-10-16T09:00:00Z', 'Europe/Moscow', '2026-10-05', '2026-10-11'],
            ['previous_week', '2026-01-04T22:00:00Z', 'UTC', '2025-12-22', '2025-12-28'],
            ['previous_month', '2024-03-01T10:00:00Z', 'UTC', '2024-02-01', '2024-02-29'],
            ['fixed_filters', '2026-10-16T09:00:00Z', 'Europe/Moscow', '2026-09-01', '2026-09-30'],
        ];
    }

    public function test_rejects_unsupported_or_silently_ignored_policy_options(): void
    {
        $this->expectException(ReportContractException::class);
        (new ReportSubscriptionPeriodResolver)->validate($this->input(['mode' => 'previous_day', 'offset' => 2]));
    }

    public function test_rejects_unknown_period_mode(): void
    {
        $this->expectException(ReportContractException::class);
        (new ReportSubscriptionPeriodResolver)->validate($this->input(['mode' => 'whenever']));
    }

    public function test_rejects_dynamic_period_without_supported_date_fields(): void
    {
        $this->expectException(ReportContractException::class);
        (new ReportSubscriptionPeriodResolver)->validate($this->input(['mode' => 'previous_day'], 'UTC', ['project_id' => 52]));
    }

    public function test_resolves_inventory_date_field_names(): void
    {
        $input = $this->input(['mode' => 'previous_day'], 'Europe/Moscow', ['period_start' => '2020-01-01', 'period_end' => '2020-01-31']);
        $data = (new ReportSubscriptionPeriodResolver)->runData($input, new DateTimeImmutable('2026-10-15T22:00:00Z'));

        self::assertSame(['period_end' => '2026-10-15', 'period_start' => '2026-10-15'], $data->filters->values);
    }

    #[DataProvider('legacyPolicies')]
    public function test_legacy_frozen_input_keeps_unsupported_policy_and_existing_dates(array $policy, array $filters): void
    {
        $legacy = $this->input($policy, 'UTC', $filters, null);
        $bytes = $legacy->canonicalBytes();
        $input = ReportSubscriptionExecutionInput::fromCanonicalBytes($bytes);
        $scheduledFor = new DateTimeImmutable('2026-10-16T09:00:00Z');
        $data = (new ReportSubscriptionPeriodResolver)->runData($input, $scheduledFor);

        self::assertNull($input->periodPolicyVersion);
        self::assertSame($bytes, $input->canonicalBytes());
        self::assertSame($legacy->digest()->value, $input->digest()->value);
        self::assertSame($legacy->filters->values, $data->filters->values);
        self::assertSame($scheduledFor, $data->asOf);
    }

    public static function legacyPolicies(): array
    {
        $dates = ['period_from' => '2026-09-01', 'period_to' => '2026-09-30'];

        return [
            [['mode' => 'previous_day', 'offset' => 2], $dates],
            [['mode' => 'whenever'], $dates],
            [['mode' => 'previous_week'], ['project_id' => 52]],
        ];
    }

    public function test_known_legacy_period_with_dates_is_resolved(): void
    {
        $input = $this->input(['mode' => 'previous_week'], 'UTC', null, null);
        $data = (new ReportSubscriptionPeriodResolver)->runData($input, new DateTimeImmutable('2026-10-16T09:00:00Z'));

        self::assertSame('2026-10-05', $data->filters->values['period_from']);
        self::assertSame('2026-10-11', $data->filters->values['period_to']);
    }

    public function test_new_version_round_trips_and_rejects_unknown_version(): void
    {
        $input = $this->input(['mode' => 'previous_week']);
        self::assertSame(1, ReportSubscriptionExecutionInput::fromCanonicalBytes($input->canonicalBytes())->periodPolicyVersion);

        $this->expectException(InvalidArgumentException::class);
        ReportSubscriptionExecutionInput::fromCanonicalBytes(str_replace('"period_policy_version":1', '"period_policy_version":2', $input->canonicalBytes()));
    }

    public function test_new_invalid_policy_cannot_use_the_legacy_fallback(): void
    {
        $this->expectException(ReportContractException::class);
        (new ReportSubscriptionPeriodResolver)->runData($this->input(['mode' => 'whenever']), new DateTimeImmutable);
    }

    public function test_frozen_saved_view_round_trips_and_reaches_resolved_period(): void
    {
        $reference = new ReportSavedViewRef('01J00000000000000000000002', 7, new Sha256Hash(str_repeat('c', 64)));
        $original = $this->input(['mode' => 'previous_week'], reference: $reference);
        $input = ReportSubscriptionExecutionInput::fromCanonicalBytes($original->canonicalBytes());
        self::assertSame($original->canonicalBytes(), $input->canonicalBytes());
        self::assertSame($original->digest()->value, $input->digest()->value);
        self::assertSame($reference->hash->value, $input->savedViewReference?->hash->value);
        self::assertSame($reference->revision, $input->savedViewReference?->revision);
        $data = (new ReportSubscriptionPeriodResolver)->runData($input, new DateTimeImmutable('2026-10-16T09:00:00Z'));
        self::assertSame($input->savedViewReference, $data->savedViewReference);
        self::assertSame('2026-10-05', $data->filters->values['period_from']);
        self::assertSame('2026-10-11', $data->filters->values['period_to']);
    }

    public function test_legacy_bytes_remain_identical_without_frozen_reference(): void
    {
        $bytes = '{"columns":["recognized_on"],"comparison":[],"contract_version":"1.0.0","definition_sha256":"'.str_repeat('a', 64).'","filters":{"period_from":"2026-09-01","period_to":"2026-09-30","project_id":52},"format":"xlsx","locale":"ru","period_policy":{"mode":"previous_week"},"report_code":"accepted_production_progress","saved_view_id":"01J00000000000000000000002","sort":{"direction":"desc","field":"recognized_on"},"timezone":"UTC"}';
        $input = ReportSubscriptionExecutionInput::fromCanonicalBytes($bytes);
        self::assertNull($input->savedViewReference);
        self::assertNull($input->periodPolicyVersion);
        self::assertSame($bytes, $input->canonicalBytes());
        self::assertSame(hash('sha256', $bytes), $input->digest()->value);
    }

    private function input(array $policy, string $timezone = 'UTC', ?array $filters = null, ?int $version = 1, ?ReportSavedViewRef $reference = null): ReportSubscriptionExecutionInput
    {
        return new ReportSubscriptionExecutionInput(
            'accepted_production_progress',
            new ReportFilterSet($filters ?? ['period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'project_id' => 52]),
            [], 'ru', '01J00000000000000000000002', 'xlsx', ['recognized_on'],
            new ReportWindowSort('recognized_on', ReportSortDirection::DESC), new DateTimeZone($timezone),
            $policy, '1.0.0', new Sha256Hash(str_repeat('a', 64)), $version, $reference,
        );
    }
}
