<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\BusinessModules\Core\Payments\Exceptions\PaymentBudgetLimitException;
use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\Http\Controllers\Api\V1\Admin\ContractController;
use App\Http\Requests\Api\V1\Admin\Contract\StoreContractRequest;
use App\Models\User;
use App\Services\Contract\ContractLifecycleService;
use App\Services\Contract\ContractService;
use Mockery;
use Tests\Support\DatabaseLessTestCase;

final class ContractCreationErrorResponseTest extends DatabaseLessTestCase
{
    public function test_contract_creation_exposes_only_safe_budget_rejections_as_advance_errors(): void
    {
        foreach ([
            [new PaymentBudgetLimitException('Выбранный бюджетный лимит исчерпан.'), 422, 'Выбранный бюджетный лимит исчерпан.'],
            [new \DomainException('private_database_details'), 400, trans_message('contract.create_error')],
        ] as [$exception, $status, $message]) {
            $request = Mockery::mock(StoreContractRequest::class)->makePartial();
            $request->initialize();
            $request->attributes->set('current_organization_id', 5);
            $user = new User;
            $user->forceFill(['id' => 7, 'current_organization_id' => 5]);
            $request->setUserResolver(static fn () => $user);
            $request->shouldReceive('toDto')->once()->andThrow($exception);
            $controller = new ContractController(
                Mockery::mock(ContractService::class),
                Mockery::mock(OfficialFormsExportService::class),
                $this->app->make(ContractLifecycleService::class),
            );

            $response = $controller->store($request);
            $this->assertSame($status, $response->getStatusCode());
            $body = $response->getData(true);
            $this->assertSame($message, $body['message']);
            $this->assertFalse($body['success']);
            if ($status === 422) {
                $this->assertSame([$message], $body['errors']['advance_payments']);
            } else {
                $this->assertStringNotContainsString('private_database_details', $response->getContent());
            }
        }
    }
}
