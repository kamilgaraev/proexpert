<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\DTOs\Contract\ContractPerformanceActDTO;
use App\Enums\CurrencyCode;
use App\Http\Requests\Api\V1\Admin\ActReport\StoreActFromWizardRequest;
use App\Http\Requests\Api\V1\Admin\Contract\PerformanceAct\StoreContractPerformanceActRequest;
use App\Http\Requests\Api\V1\Admin\Contract\PerformanceAct\UpdateContractPerformanceActRequest;
use App\Models\User;
use App\Services\ActReport\ActReportAccessService;
use App\Services\Contract\ContractAccessService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PerformanceActCurrencyApiContractTest extends TestCase
{
    #[Test]
    public function accepted_act_api_requires_explicit_currency_and_pins_it_to_pivot_rows(): void
    {
        $rules = (new StoreContractPerformanceActRequest)->rules();
        self::assertContains('required', $rules['currency']);
        $this->assertCurrencyEnumRule($rules['currency']);
        $this->assertCurrencyEnumRule((new UpdateContractPerformanceActRequest)->rules()['currency']);

        $dto = new ContractPerformanceActDTO(
            project_id: 7,
            act_document_number: 'КС-2/15',
            act_date: '2026-07-30',
            description: null,
            is_approved: true,
            approval_date: '2026-07-30',
            completed_works: [[
                'completed_work_id' => 91,
                'included_quantity' => '2.500',
                'included_amount' => '25.00',
            ]],
            currency: 'rub',
        );

        self::assertSame('RUB', $dto->toArray()['currency']);
        self::assertSame('RUB', $dto->getCompletedWorksForSync()[91]['currency']);
    }

    #[Test]
    public function accepted_act_status_transition_does_not_replace_immutable_lines_implicitly(): void
    {
        $statusOnly = new ContractPerformanceActDTO(
            project_id: 7,
            act_document_number: 'КС-2/15',
            act_date: '2026-07-30',
            description: null,
            is_approved: false,
        );
        $withLines = new ContractPerformanceActDTO(
            project_id: 7,
            act_document_number: 'КС-2/15',
            act_date: '2026-07-30',
            description: null,
            completedWorksProvided: true,
        );

        self::assertFalse($statusOnly->completedWorksProvided);
        self::assertTrue($withLines->completedWorksProvided);

        $serviceSource = file_get_contents(
            dirname(__DIR__, 4).'/app/Services/Contract/ContractPerformanceActService.php',
        );
        self::assertIsString($serviceSource);
        self::assertStringContainsString(
            '$wasAccepted && $actDTO->completedWorksProvided',
            $serviceSource,
        );
    }

    #[Test]
    public function partial_update_serializes_only_fields_present_in_the_request(): void
    {
        $dto = new ContractPerformanceActDTO(
            project_id: 7,
            act_document_number: 'КС-2/16',
            act_date: '2026-08-06',
            description: 'Новое описание',
            is_approved: false,
            approval_date: null,
            amount: 500,
            currency: 'rub',
            partialUpdate: true,
            providedFields: ['description'],
        );

        self::assertSame(['description' => 'Новое описание'], $dto->toArray());

        $explicitNull = new ContractPerformanceActDTO(
            project_id: 7,
            act_document_number: null,
            act_date: '2026-08-06',
            description: null,
            is_approved: false,
            approval_date: null,
            partialUpdate: true,
            providedFields: ['act_document_number', 'description', 'approval_date'],
        );
        self::assertSame([
            'act_document_number' => null,
            'description' => null,
            'approval_date' => null,
        ], $explicitNull->toArray());

        $updateRules = (new UpdateContractPerformanceActRequest)->rules();
        self::assertNotContains('nullable', $updateRules['amount']);

        $reversal = new ContractPerformanceActDTO(
            project_id: 7,
            act_document_number: 'КС-2/16',
            act_date: '2026-08-06',
            description: null,
            is_approved: false,
            approval_date: '2026-08-06',
            partialUpdate: true,
            providedFields: ['is_approved'],
        );
        self::assertSame([
            'is_approved' => false,
            'approval_date' => null,
        ], $reversal->toArray());

        $explicitEmptyWorks = new ContractPerformanceActDTO(
            project_id: 7,
            act_document_number: null,
            act_date: '',
            description: null,
            completedWorksProvided: true,
            partialUpdate: true,
            providedFields: ['completed_works'],
        );
        self::assertSame([], $explicitEmptyWorks->toArray());

        $serviceSource = file_get_contents(
            dirname(__DIR__, 4).'/app/Services/Contract/ContractPerformanceActService.php',
        );
        self::assertIsString($serviceSource);
        self::assertStringContainsString('if ($actDTO->completedWorksProvided)', $serviceSource);
        self::assertStringNotContainsString(
            '$actDTO->completedWorksProvided && ! empty($actDTO->completed_works)',
            $serviceSource,
        );
    }

    #[Test]
    public function act_quantity_and_scope_contracts_are_explicit_and_server_owned(): void
    {
        $storeRules = (new StoreContractPerformanceActRequest)->rules();
        $updateRules = (new UpdateContractPerformanceActRequest)->rules();
        foreach ([$storeRules, $updateRules] as $rules) {
            self::assertSame(['prohibited'], $rules['organization_id']);
            self::assertSame(['prohibited'], $rules['organization_id_for_show']);
            self::assertSame(['prohibited'], $rules['project_id']);
            self::assertContains('decimal:0,3', $rules['completed_works.*.included_quantity']);
            self::assertContains('min:0.001', $rules['completed_works.*.included_quantity']);
        }

        $wizardRules = (new StoreActFromWizardRequest)->rules();
        self::assertContains('decimal:0,4', $wizardRules['selected_works.*.quantity']);
        self::assertContains('min:0.0001', $wizardRules['selected_works.*.quantity']);

        $reservationSource = file_get_contents(
            dirname(__DIR__, 4).'/app/Services/Acting/ActingQuantityReservationService.php',
        );
        self::assertIsString($reservationSource);
        self::assertStringContainsString("performance_act_lines as acting_lines", $reservationSource);
        self::assertStringContainsString("performance_act_completed_works as acting_links", $reservationSource);
        self::assertStringContainsString('whereNotExists', $reservationSource);
    }

    #[Test]
    public function act_access_prefers_the_server_current_organization_context(): void
    {
        $user = new User();
        $user->forceFill([
            'organization_id' => 7,
            'current_organization_id' => 38,
        ]);
        $request = Request::create('/api/v1/admin/act-reports');
        $request->setUserResolver(static fn (): User => $user);
        $request->attributes->set('current_organization_id', 41);
        $request->attributes->set('current_organization', (object) ['id' => 43]);

        $service = new ActReportAccessService($this->createMock(ContractAccessService::class));

        self::assertSame(41, $service->currentOrganizationId($request));
        $request->attributes->remove('current_organization_id');
        self::assertSame(43, $service->currentOrganizationId($request));
        $request->attributes->remove('current_organization');
        self::assertSame(38, $service->currentOrganizationId($request));
    }

    private function assertCurrencyEnumRule(array $rules): void
    {
        $enumRules = array_values(array_filter(
            $rules,
            static fn (mixed $rule): bool => $rule instanceof Enum,
        ));

        self::assertCount(1, $enumRules);
        $type = (new \ReflectionClass($enumRules[0]))->getProperty('type');
        $type->setAccessible(true);
        self::assertSame(CurrencyCode::class, $type->getValue($enumRules[0]));
        self::assertSame(['RUB', 'USD', 'EUR'], CurrencyCode::values());
        self::assertNull(CurrencyCode::tryFrom('CHF'));
    }
}
