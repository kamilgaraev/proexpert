<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Features\Budgeting\Models\BudgetPeriod;
use App\BusinessModules\Features\Budgeting\Models\BudgetPeriodClosure;
use App\BusinessModules\Features\Budgeting\Services\BudgetPeriodClosureService;
use App\Models\User;
use DomainException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class BudgetPeriodClosureServiceTest extends TestCase
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

        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function test_locked_statuses_block_regular_mutations(): void
    {
        $service = new BudgetPeriodClosureService();

        $this->assertFalse($service->isLockedStatus(BudgetPeriodClosureService::STATUS_OPEN));
        $this->assertFalse($service->isLockedStatus(BudgetPeriodClosureService::STATUS_REOPENED_FOR_ADJUSTMENT));
        $this->assertTrue($service->isLockedStatus(BudgetPeriodClosureService::STATUS_CLOSING));
        $this->assertTrue($service->isLockedStatus(BudgetPeriodClosureService::STATUS_CLOSED));
        $this->assertTrue($service->isLockedStatus(BudgetPeriodClosureService::STATUS_SOFT_CLOSED));
        $this->assertTrue($service->isLockedStatus(BudgetPeriodClosureService::STATUS_ARCHIVED));

        $service->assertMutableStatus(BudgetPeriodClosureService::STATUS_OPEN);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Бюджетный период закрыт. Обычные изменения по нему недоступны.');

        $service->assertMutableStatus(BudgetPeriodClosureService::STATUS_CLOSED);
    }

    public function test_period_closure_summary_returns_human_readable_closed_state(): void
    {
        $service = new BudgetPeriodClosureService();
        $period = new BudgetPeriod();
        $period->forceFill([
            'uuid' => 'period-uuid',
            'status' => BudgetPeriodClosureService::STATUS_CLOSED,
        ]);

        $user = new User();
        $user->forceFill([
            'id' => 15,
            'name' => 'Финансовый директор',
            'email' => 'finance@example.test',
        ]);

        $closure = new BudgetPeriodClosure();
        $closure->forceFill([
            'closure_status' => BudgetPeriodClosureService::STATUS_CLOSED,
            'closure_mode' => 'management',
            'reason' => 'Месяц сверили с план-факт анализом',
        ]);
        $closure->setRelation('closedBy', $user);
        $period->setRelation('latestClosure', $closure);

        $summary = $service->periodClosureSummary($period);

        $this->assertSame('period-uuid', $summary['period_id']);
        $this->assertSame('closed', $summary['status']);
        $this->assertSame('Закрыт', $summary['status_label']);
        $this->assertTrue($summary['is_closed']);
        $this->assertSame('Месяц сверили с план-факт анализом', $summary['closed_reason']);
        $this->assertSame('management', $summary['closure_mode']);
        $this->assertSame(15, $summary['closed_by']['id']);
        $this->assertSame('Финансовый директор', $summary['closed_by']['name']);
    }

    public function test_locked_operations_are_translated_for_api_contract(): void
    {
        $operations = (new BudgetPeriodClosureService())->lockedOperations();
        $labels = array_column($operations, 'label', 'code');

        $this->assertSame('Создание, изменение и удаление строк бюджета', $labels['budget_lines']);
        $this->assertSame('Импорт бюджета', $labels['budget_import']);
        $this->assertArrayNotHasKey('payload', $labels);
    }
}
