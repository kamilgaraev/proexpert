<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Features\Budgeting\DTOs\EpmDataMartScope;
use App\BusinessModules\Features\Budgeting\DTOs\EpmDataMartStatus;
use App\BusinessModules\Features\Budgeting\Models\EpmDataMartRecalculationRun;
use App\BusinessModules\Features\Budgeting\Models\EpmDataMartSnapshot;
use App\BusinessModules\Features\Budgeting\Services\EpmDataMartHealthService;
use App\BusinessModules\Features\Budgeting\Services\EpmDataMartPayloadProjector;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class EpmDataMartHealthServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $loader = new FileLoader(new Filesystem(), dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'lang');
        $translator = new Translator($loader, 'ru');

        $container->instance('translator', $translator);
        $container->instance('config', new Repository([
            'app' => [
                'locale' => 'ru',
                'fallback_locale' => 'ru',
            ],
            'budgeting' => [
                'epm_data_mart' => [
                    'stale_after_minutes' => 120,
                    'slow_after_ms' => 30000,
                    'running_stuck_after_minutes' => 30,
                    'health_history_limit' => 1000,
                ],
            ],
        ]));
        $container->instance('app', new class {
            public function getLocale(): string
            {
                return 'ru';
            }
        });

        Container::setInstance($container);
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_health_payload_exposes_counts_thresholds_duration_and_staleness(): void
    {
        $generatedAt = CarbonImmutable::parse('2026-06-10T12:00:00+03:00');
        $payload = (new EpmDataMartHealthService())->buildPayload([
            $this->snapshot(1, EpmDataMartScope::CFO_COMMAND_CENTER, 'cfo-rub', EpmDataMartStatus::SUCCEEDED, '2026-06-10T11:40:00+03:00'),
            $this->snapshot(2, EpmDataMartScope::PROJECT_PORTFOLIO_DASHBOARD, 'portfolio-rub', EpmDataMartStatus::PARTIAL, '2026-06-10T11:20:00+03:00'),
            $this->snapshot(3, EpmDataMartScope::PLAN_FACT, 'plan-rub', EpmDataMartStatus::SUCCEEDED, '2026-06-10T09:00:00+03:00', '2026-06-10T11:00:00+03:00'),
        ], [
            $this->recalculationRun(10, EpmDataMartScope::CFO_COMMAND_CENTER, 'cfo-rub', EpmDataMartStatus::SUCCEEDED, [
                'queued_at' => '2026-06-10T11:30:00+03:00',
                'started_at' => '2026-06-10T11:31:00+03:00',
                'finished_at' => '2026-06-10T11:41:00+03:00',
                'duration_ms' => 15000,
            ]),
            $this->recalculationRun(11, EpmDataMartScope::PROJECT_PORTFOLIO_DASHBOARD, 'portfolio-rub', EpmDataMartStatus::SUCCEEDED, [
                'finished_at' => '2026-06-10T11:21:00+03:00',
                'duration_ms' => 45000,
            ]),
            $this->recalculationRun(12, EpmDataMartScope::PLAN_FACT, 'plan-rub', EpmDataMartStatus::SUCCEEDED, [
                'finished_at' => '2026-06-10T09:01:00+03:00',
                'duration_ms' => 120000,
            ]),
            $this->recalculationRun(13, EpmDataMartScope::CASH_GAP, 'cash-gap-rub', EpmDataMartStatus::QUEUED, [
                'queued_at' => '2026-06-10T11:55:00+03:00',
            ]),
            $this->recalculationRun(14, EpmDataMartScope::WIP_FORECAST, 'wip-rub', EpmDataMartStatus::RUNNING, [
                'queued_at' => '2026-06-10T10:55:00+03:00',
                'started_at' => '2026-06-10T11:00:00+03:00',
            ]),
        ], $generatedAt);

        $this->assertSame(EpmDataMartStatus::STALE, $payload['status']);
        $this->assertSame(1, $payload['counts'][EpmDataMartStatus::QUEUED]);
        $this->assertSame(1, $payload['counts'][EpmDataMartStatus::RUNNING]);
        $this->assertSame(1, $payload['counts'][EpmDataMartStatus::SUCCEEDED]);
        $this->assertSame(1, $payload['counts'][EpmDataMartStatus::PARTIAL]);
        $this->assertSame(1, $payload['counts'][EpmDataMartStatus::STALE]);
        $this->assertSame(0, $payload['counts'][EpmDataMartStatus::FAILED]);
        $this->assertSame(15000, $payload['duration_ms']['last']);
        $this->assertSame(45000, $payload['duration_ms']['p50']);
        $this->assertSame(120000, $payload['duration_ms']['p95']);
        $this->assertSame(120000, $payload['duration_ms']['max']);
        $this->assertSame('2026-06-10T11:40:00+03:00', $payload['last_success_at']);
        $this->assertSame('2026-06-10T11:55:00+03:00', $payload['last_attempt_at']);
        $this->assertSame(120, $payload['thresholds']['stale_after_minutes']);
        $this->assertSame(30000, $payload['thresholds']['slow_after_ms']);
        $this->assertSame(30, $payload['thresholds']['running_stuck_after_minutes']);
        $this->assertSame(180, $payload['staleness']['max_lag_minutes']);
        $this->assertSame(1, $payload['staleness']['stale_scopes_count']);
        $this->assertSame(2, $payload['staleness']['slow_scopes_count']);
        $this->assertSame(1, $payload['staleness']['stuck_scopes_count']);
        $this->assertSame('ProHelper', $payload['source_of_truth']['primary']['label']);
        $this->assertFalse($payload['source_of_truth']['external_confirmation']['1c']['stores_accounting_duplicate']);
        $this->assertStringNotContainsString('epm_data_mart.', $payload['freshness']['message']);
    }

    public function test_empty_mart_returns_unavailable_without_failure(): void
    {
        $payload = (new EpmDataMartHealthService())->buildPayload([], [], CarbonImmutable::parse('2026-06-10T12:00:00+03:00'));

        $this->assertSame(EpmDataMartStatus::UNAVAILABLE, $payload['status']);
        $this->assertNull($payload['last_success_at']);
        $this->assertNull($payload['last_attempt_at']);
        $this->assertSame([], $payload['staleness']['items']);
        $this->assertSame([], $payload['failed_scopes']);
        $this->assertSame([
            EpmDataMartStatus::QUEUED => 0,
            EpmDataMartStatus::RUNNING => 0,
            EpmDataMartStatus::SUCCEEDED => 0,
            EpmDataMartStatus::PARTIAL => 0,
            EpmDataMartStatus::STALE => 0,
            EpmDataMartStatus::FAILED => 0,
        ], $payload['counts']);
    }

    public function test_failed_scopes_are_sanitized(): void
    {
        $payload = (new EpmDataMartHealthService())->buildPayload([], [
            $this->recalculationRun(20, EpmDataMartScope::PROJECT_MARGIN, 'margin-rub', EpmDataMartStatus::FAILED, [
                'finished_at' => '2026-06-10T11:58:00+03:00',
                'duration_ms' => 2000,
                'error_summary' => [
                    'message' => 'SQLSTATE[23505] duplicate key, raw payload contains secret token and stack trace',
                    'retryable' => false,
                ],
            ]),
        ], CarbonImmutable::parse('2026-06-10T12:00:00+03:00'));
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertSame(EpmDataMartStatus::FAILED, $payload['status']);
        $this->assertSame(1, $payload['counts'][EpmDataMartStatus::FAILED]);
        $this->assertSame('Маржинальность проектов', $payload['failed_scopes'][0]['report_label']);
        $this->assertSame('Пересчет не завершился. Поддержке нужно проверить обработку и повторить обновление.', $payload['failed_scopes'][0]['reason']);
        $this->assertFalse($payload['failed_scopes'][0]['retryable']);
        $this->assertStringNotContainsString('SQLSTATE', $json);
        $this->assertStringNotContainsString('payload', $json);
        $this->assertStringNotContainsString('secret', $json);
        $this->assertStringNotContainsString('stack trace', $json);
    }

    private function snapshot(
        int $id,
        string $reportScope,
        string $scopeHash,
        string $status,
        string $generatedAt,
        ?string $staleAt = null,
    ): EpmDataMartSnapshot {
        $snapshot = new EpmDataMartSnapshot();
        $snapshot->setRawAttributes([
            'id' => $id,
            'uuid' => 'snapshot-' . $id,
            'organization_id' => 7,
            'report_scope' => $reportScope,
            'scope_hash' => $scopeHash,
            'status' => $status,
            'formula_version' => EpmDataMartPayloadProjector::FORMULA_VERSION,
            'source_hash' => str_repeat('a', 64),
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'as_of_date' => '2026-06-30',
            'project_id' => 25,
            'currency' => 'RUB',
            'generated_at' => $generatedAt,
            'stale_at' => $staleAt,
        ], true);

        return $snapshot;
    }

    private function recalculationRun(int $id, string $reportScope, string $scopeHash, string $status, array $overrides = []): EpmDataMartRecalculationRun
    {
        $run = new EpmDataMartRecalculationRun();
        $run->setRawAttributes([
            'id' => $id,
            'uuid' => 'run-' . $id,
            'organization_id' => 7,
            'report_scope' => $reportScope,
            'scope_hash' => $scopeHash,
            'status' => $status,
            'formula_version' => EpmDataMartPayloadProjector::FORMULA_VERSION,
            'filters' => json_encode([
                'organization_id' => 7,
                'report_scope' => $reportScope,
                'period_start' => '2026-06-01',
                'period_end' => '2026-06-30',
                'as_of_date' => '2026-06-30',
                'project_id' => 25,
                'currency' => 'RUB',
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'queued_at' => $overrides['queued_at'] ?? null,
            'started_at' => $overrides['started_at'] ?? null,
            'finished_at' => $overrides['finished_at'] ?? null,
            'generated_at' => $overrides['generated_at'] ?? null,
            'duration_ms' => $overrides['duration_ms'] ?? null,
            'error_summary' => isset($overrides['error_summary'])
                ? json_encode($overrides['error_summary'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                : null,
        ], true);

        return $run;
    }
}
