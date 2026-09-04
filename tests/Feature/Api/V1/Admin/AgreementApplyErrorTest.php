<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\AgreementController;
use App\Models\Contract;
use App\Models\SupplementaryAgreement;
use App\Models\User;
use App\Services\Contract\ContractService;
use App\Services\Contract\SupplementaryAgreementService;
use DomainException;
use Illuminate\Http\Request;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AgreementApplyErrorTest extends TestCase
{
    public function refreshDatabase(): void {}

    public function test_expected_rejection_is_explained_and_unexpected_error_is_hidden(): void
    {
        foreach ([
            [new DomainException(trans_message('agreements.contract_total_negative')), 422, trans_message('agreements.contract_total_negative')],
            [new RuntimeException('private database failure'), 400, trans_message('agreements.apply_error')],
            [new DomainException('private database failure'), 400, trans_message('agreements.apply_error')],
        ] as [$error, $status, $message]) {
            $contract = new Contract;
            $contract->forceFill(['id' => 274, 'project_id' => 52, 'organization_id' => 7, 'is_multi_project' => false]);
            $agreement = new SupplementaryAgreement;
            $agreement->setRelation('contract', $contract);
            $user = new User;
            $user->forceFill(['id' => 39, 'current_organization_id' => 7]);
            $request = Request::create('/apply?project_id=52', 'POST');
            $request->setUserResolver(static fn () => $user);

            $service = Mockery::mock(SupplementaryAgreementService::class);
            $service->shouldReceive('getById')->once()->with(43)->andReturn($agreement);
            $service->shouldReceive('applyChangesToContract')->once()->with(43)->andThrow($error);
            $contracts = Mockery::mock(ContractService::class);
            $contracts->shouldReceive('getContractById')->once()->with(274, 7, 52)->andReturn($contract);

            $response = (new AgreementController($service, $contracts))->applyChanges($request, 52, 43);

            self::assertSame($status, $response->getStatusCode());
            self::assertSame($message, $response->getData(true)['message']);
            self::assertStringNotContainsString('private database failure', $response->getContent());
        }
    }
}
