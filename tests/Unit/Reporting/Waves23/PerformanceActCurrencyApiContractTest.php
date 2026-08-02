<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\DTOs\Contract\ContractPerformanceActDTO;
use App\Http\Requests\Api\V1\Admin\Contract\PerformanceAct\StoreContractPerformanceActRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PerformanceActCurrencyApiContractTest extends TestCase
{
    #[Test]
    public function accepted_act_api_requires_explicit_currency_and_pins_it_to_pivot_rows(): void
    {
        $rules = (new StoreContractPerformanceActRequest)->rules();
        self::assertContains('required', $rules['currency']);
        self::assertContains('regex:/^[A-Z]{3}$/', $rules['currency']);

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
}
