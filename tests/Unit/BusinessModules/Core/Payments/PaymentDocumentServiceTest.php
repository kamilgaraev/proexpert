<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\ApprovalWorkflowService;
use App\BusinessModules\Core\Payments\Services\PaymentAuditService;
use App\BusinessModules\Core\Payments\Services\PaymentBudgetLimitService;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentService;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentStateMachine;
use App\BusinessModules\Core\Payments\Services\PaymentValidationService;
use App\BusinessModules\Features\Budgeting\Services\BudgetLimitCheckService;
use App\Domain\Authorization\Services\ModulePermissionChecker;
use DomainException;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;

final class PaymentDocumentServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Mockery::close();

        parent::tearDown();
    }

    public function test_submit_does_not_log_expected_domain_transition_as_error(): void
    {
        $document = new PaymentDocument();
        $document->id = 160;
        $document->amount = 1000;

        $app = new Container();
        Facade::setFacadeApplication($app);

        $db = Mockery::mock();
        $db->shouldReceive('beginTransaction')->once();
        $db->shouldReceive('rollBack')->once();
        $app->instance('db', $db);

        $log = Mockery::mock();
        $log->shouldReceive('error')
            ->with('payment_document.submit_failed', Mockery::any())
            ->never();
        $log->shouldReceive('warning')
            ->with('payment_document.submit_rejected', Mockery::on(
                static fn (array $context): bool => ($context['document_id'] ?? null) === 160
                    && ($context['error'] ?? null) === "Недопустимый переход из статуса 'Оплачен' в 'Отправлен'"
            ))
            ->once();
        $app->instance('log', $log);

        $validator = Mockery::mock(PaymentValidationService::class);
        $validator->shouldReceive('validateBeforeSubmission')
            ->once()
            ->with($document)
            ->andThrow(new DomainException("Недопустимый переход из статуса 'Оплачен' в 'Отправлен'"));

        $stateMachine = Mockery::mock(PaymentDocumentStateMachine::class);
        $stateMachine->shouldReceive('submit')
            ->never();

        $service = new PaymentDocumentService(
            $stateMachine,
            Mockery::mock(ApprovalWorkflowService::class),
            $validator,
            new PaymentBudgetLimitService(
                new BudgetLimitCheckService(),
                Mockery::mock(ModulePermissionChecker::class),
            ),
            Mockery::mock(PaymentAuditService::class),
        );

        $this->expectException(DomainException::class);

        $service->submit($document);
    }
}
