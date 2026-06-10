<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Features\Budgeting\DTOs\EpmDataMartScope;
use App\BusinessModules\Features\Budgeting\Jobs\RecalculateEpmDataMartSnapshotJob;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class EpmDataMartScopeAndJobTest extends TestCase
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
                    'queue' => 'epm-data-mart',
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

    public function test_scope_hash_is_stable_for_same_business_scope_and_ignores_runtime_context(): void
    {
        $left = EpmDataMartScope::fromInput(EpmDataMartScope::PROJECT_MARGIN, [
            'current_organization_id' => 7,
            'organization_id' => 7,
            'report_scope' => 'project_margin',
            'current_project_id' => 999,
            '_skip_data_mart_meta' => true,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'project_id' => 101,
            'currency' => 'rub',
            'group_by' => ['project', 'currency'],
        ]);
        $right = EpmDataMartScope::fromInput(EpmDataMartScope::PROJECT_MARGIN, [
            'organization_id' => 7,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'project_id' => 101,
            'currency' => 'RUB',
            'group_by' => ['project', 'currency'],
        ]);
        $changed = EpmDataMartScope::fromInput(EpmDataMartScope::PROJECT_MARGIN, [
            'organization_id' => 7,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'project_id' => 101,
            'currency' => 'USD',
            'group_by' => ['project', 'currency'],
        ]);

        $this->assertSame($left->scopeHash(), $right->scopeHash());
        $this->assertNotSame($left->scopeHash(), $changed->scopeHash());
    }

    public function test_recalculation_job_is_unique_and_guarded_against_overlap(): void
    {
        $job = new RecalculateEpmDataMartSnapshotJob(55);
        $middleware = $job->middleware();

        $this->assertSame('epm-data-mart', $job->queue);
        $this->assertSame('55', $job->uniqueId());
        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
    }

    public function test_job_defers_failed_status_until_final_queue_failure(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3)
            . '/app/BusinessModules/Features/Budgeting/Jobs/RecalculateEpmDataMartSnapshotJob.php');

        $this->assertIsString($source);
        $this->assertStringContainsString('recalculateRun($this->runId, false)', $source);
    }
}
