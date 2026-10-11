<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentBudgetLimitService;
use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\BusinessModules\Features\Budgeting\Services\BudgetLimitCheckService;
use App\Domain\Authorization\Services\ModulePermissionChecker;
use App\Modules\Core\AccessController;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class PaymentBudgetPeriodBoundaryTest extends TestCase
{
    public function test_budget_starting_mid_month_is_included_without_using_bdr_as_payment_limit(): void
    {
        $previousResolver = Model::getConnectionResolver();
        $connection = new class(static fn () => throw new RuntimeException('Unit test must not connect to a database.'), 'unit') extends PostgresConnection {
            public array $capturedBindings = [];
            public string $capturedQuery = '';

            public function select($query, $bindings = [], $useReadPdo = true): array
            {
                $this->capturedQuery = $query;
                $this->capturedBindings = $bindings;
                return [];
            }
        };
        $resolver = new ConnectionResolver(['unit' => $connection]);
        $resolver->setDefaultConnection('unit');
        Model::setConnectionResolver($resolver);

        try {
            $service = new PaymentBudgetLimitService(new BudgetLimitCheckService, new ModulePermissionChecker($this->createMock(AccessController::class)));
            $document = new PaymentDocument(['organization_id' => 7, 'currency' => 'RUB']);
            $article = new BudgetArticle;
            $article->id = 2;
            $center = new ResponsibilityCenter;
            $center->id = 3;
            $month = Carbon::parse('2026-10-01');

            (new ReflectionMethod($service, 'resolveBudgetLine'))->invoke($service, $document, $month, $article, $center);

            self::assertStringContainsString('"starts_at"::date <= ?', $connection->capturedQuery);
            self::assertStringContainsString('"ends_at"::date >= ?', $connection->capturedQuery);
            self::assertContains('2026-10-31', $connection->capturedBindings);
            self::assertContains('2026-10-01', $connection->capturedBindings);
            self::assertContains('bdds', $connection->capturedBindings);
            self::assertContains('consolidated', $connection->capturedBindings);
            self::assertNotContains('bdr', $connection->capturedBindings);
            self::assertSame('2026-10-01', $month->toDateString());
        } finally {
            $previousResolver === null ? Model::unsetConnectionResolver() : Model::setConnectionResolver($previousResolver);
        }
    }
}
