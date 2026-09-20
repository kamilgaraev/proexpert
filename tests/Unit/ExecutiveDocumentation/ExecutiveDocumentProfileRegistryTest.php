<?php

declare(strict_types=1);

namespace Tests\Unit\ExecutiveDocumentation;

use App\BusinessModules\Features\ExecutiveDocumentation\Support\ExecutiveDocumentProfileValidator;
use PHPUnit\Framework\TestCase;

final class ExecutiveDocumentProfileRegistryTest extends TestCase
{
    public function test_validator_rejects_unknown_and_invalid_profile_values(): void
    {
        $validator = new ExecutiveDocumentProfileValidator();
        $profile = [
            'fields' => [
                ['key' => 'control_number', 'label' => 'Номер', 'type' => 'text', 'required' => true],
                ['key' => 'received_at', 'label' => 'Дата', 'type' => 'date', 'required' => true],
                ['key' => 'control_result', 'label' => 'Результат', 'type' => 'select', 'required' => true, 'options' => ['accepted', 'rejected']],
            ],
        ];

        $errors = $validator->validate($profile, [
            'control_number' => 'ВК-1',
            'received_at' => '20.09.2026',
            'unknown' => 'not allowed',
        ]);

        self::assertArrayHasKey('received_at', $errors);
        self::assertArrayHasKey('unknown', $errors);
        self::assertArrayHasKey('control_result', $errors);
    }
}
