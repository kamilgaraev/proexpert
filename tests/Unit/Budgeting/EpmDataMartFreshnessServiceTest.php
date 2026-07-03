<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Features\Budgeting\DTOs\EpmDataMartScope;
use App\BusinessModules\Features\Budgeting\DTOs\EpmDataMartStatus;
use App\BusinessModules\Features\Budgeting\Models\EpmDataMartRecalculationRun;
use App\BusinessModules\Features\Budgeting\Models\EpmDataMartSnapshot;
use App\BusinessModules\Features\Budgeting\Services\EpmDataMartFreshnessService;
use App\BusinessModules\Features\Budgeting\Services\EpmDataMartPayloadProjector;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class EpmDataMartFreshnessServiceTest extends TestCase
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

    public function test_reports_online_when_snapshot_is_empty(): void
    {
        $metadata = (new EpmDataMartFreshnessService())->metadataFor($this->scope(), null);

        $this->assertSame(EpmDataMartStatus::ONLINE, $metadata['status']);
        $this->assertSame('online', $metadata['calculation_source']);
        $this->assertNull($metadata['snapshot']);
        $this->assertStringNotContainsString('budgeting.', $metadata['message']);
        $this->assertStringNotContainsString('Snapshot', $metadata['message']);
    }

    public function test_active_run_status_is_exposed_without_snapshot(): void
    {
        $run = new EpmDataMartRecalculationRun();
        $run->setRawAttributes([
            'uuid' => 'run-queued',
            'status' => EpmDataMartStatus::QUEUED,
            'queued_at' => '2026-06-10 10:00:00',
        ], true);

        $metadata = (new EpmDataMartFreshnessService())->metadataFor($this->scope(), null, $run);

        $this->assertSame(EpmDataMartStatus::QUEUED, $metadata['status']);
        $this->assertSame('online', $metadata['calculation_source']);
        $this->assertSame(EpmDataMartStatus::QUEUED, $metadata['recalculation']['status']);
    }

    public function test_snapshot_statuses_cover_partial_and_stale_formula_versions(): void
    {
        $service = new EpmDataMartFreshnessService();
        $partial = $this->snapshot(EpmDataMartStatus::PARTIAL, EpmDataMartPayloadProjector::FORMULA_VERSION);
        $stale = $this->snapshot(EpmDataMartStatus::SUCCEEDED, 'old_formula_v0');

        $partialMetadata = $service->metadataFor($this->scope(), $partial);
        $staleMetadata = $service->metadataFor($this->scope(), $stale);

        $this->assertSame(EpmDataMartStatus::PARTIAL, $partialMetadata['status']);
        $this->assertSame('data_mart', $partialMetadata['calculation_source']);
        $this->assertSame('freshness_confirmation_only', $partialMetadata['snapshot']['source_refs']['external_confirmation']['1c']['role']);
        $this->assertFalse($partialMetadata['snapshot']['source_refs']['external_confirmation']['1c']['stores_accounting_duplicate']);
        $this->assertSame(EpmDataMartStatus::STALE, $staleMetadata['status']);
    }

    public function test_failed_run_exposes_only_sanitized_error_summary(): void
    {
        $run = new EpmDataMartRecalculationRun();
        $run->setRawAttributes([
            'uuid' => 'run-failed',
            'status' => EpmDataMartStatus::FAILED,
            'finished_at' => '2026-06-10 10:05:00',
            'error_summary' => json_encode([
                'code' => 'epm_data_mart_recalculation_failed',
                'message' => 'Не удалось обновить витрину данных. Можно повторить пересчет позже.',
                'retryable' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ], true);

        $metadata = (new EpmDataMartFreshnessService())->metadataFor($this->scope(), null, $run);
        $json = json_encode($metadata['recalculation']['error_summary'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertSame(EpmDataMartStatus::FAILED, $metadata['status']);
        $this->assertSame('online', $metadata['calculation_source']);
        $this->assertStringNotContainsString('trace', $json);
        $this->assertStringNotContainsString('SQLSTATE', $json);
        $this->assertStringNotContainsString('raw payload', $json);
        $this->assertStringNotContainsString('secret', $json);
    }

    private function scope(): EpmDataMartScope
    {
        return EpmDataMartScope::fromInput(EpmDataMartScope::PROJECT_MARGIN, [
            'organization_id' => 7,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'project_id' => 101,
            'currency' => 'RUB',
        ]);
    }

    private function snapshot(string $status, string $formulaVersion): EpmDataMartSnapshot
    {
        $snapshot = new EpmDataMartSnapshot();
        $snapshot->setRawAttributes([
            'uuid' => 'snapshot-' . $status,
            'status' => $status,
            'formula_version' => $formulaVersion,
            'source_hash' => str_repeat('a', 64),
            'source_refs' => json_encode([
                'management_source_of_truth' => 'most',
                'external_confirmation' => [
                    '1c' => [
                        'role' => 'freshness_confirmation_only',
                        'stores_accounting_duplicate' => false,
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'generated_at' => '2026-06-10 10:00:00',
        ], true);

        return $snapshot;
    }
}
