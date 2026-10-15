<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\AgreementController;
use App\Models\User;
use App\Services\Contract\ProjectSupplementaryAgreementService;
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
            $user = new User;
            $user->forceFill(['id' => 39, 'current_organization_id' => 7]);
            $request = Request::create('/apply?project_id=52', 'POST');
            $request->setUserResolver(static fn () => $user);

            $service = Mockery::mock(ProjectSupplementaryAgreementService::class);
            $service->shouldReceive('applyChangesToContract')->once()->with($user, 7, 52, 43)->andThrow($error);

            $response = (new AgreementController($service))->applyChanges($request, 52, 43);

            self::assertSame($status, $response->getStatusCode());
            self::assertSame($message, $response->getData(true)['message']);
            self::assertStringNotContainsString('private database failure', $response->getContent());
        }
    }
}
