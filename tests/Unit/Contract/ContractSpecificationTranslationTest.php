<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use PHPUnit\Framework\TestCase;

class ContractSpecificationTranslationTest extends TestCase
{
    public function test_contract_specification_messages_are_user_facing(): void
    {
        $messages = require dirname(__DIR__, 3) . '/lang/ru/contract.php';
        $keys = [
            'access_denied',
            'contract_deleted',
            'contract_mismatch',
            'specification_already_attached',
            'specification_attach_error',
            'specification_attached',
            'specification_create_error',
            'specification_created',
            'specification_detach_error',
            'specification_detached',
            'specification_not_found',
            'specification_retrieve_error',
        ];

        foreach ($keys as $key) {
            self::assertArrayHasKey($key, $messages);
            self::assertIsString($messages[$key]);
            self::assertNotSame("contract.{$key}", $messages[$key]);
            self::assertMatchesRegularExpression('/[А-Яа-яЁё]/u', $messages[$key]);
        }
    }
}
