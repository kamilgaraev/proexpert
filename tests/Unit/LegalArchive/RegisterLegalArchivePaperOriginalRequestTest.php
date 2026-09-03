<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Http\Requests\Api\V1\Admin\LegalArchive\RegisterLegalArchivePaperOriginalRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

final class RegisterLegalArchivePaperOriginalRequestTest extends TestCase
{
    public function test_accepts_complete_manual_original_and_rejects_invalid_fields(): void
    {
        $rules = (new RegisterLegalArchivePaperOriginalRequest)->rules();
        $factory = new Factory(new Translator(new ArrayLoader, 'ru'));
        $valid = [
            'lock_version' => 0,
            'document_version_id' => 1,
            'idempotency_key' => 'paper-original-request',
            'signed_at' => '2026-01-15',
            'storage_location' => 'Шкаф 1',
            'authority_confirmed' => true,
            'signers' => [['kind' => 'manual', 'name' => 'Иван', 'position' => 'Директор', 'authority_basis' => 'Устав']],
        ];
        self::assertTrue($factory->make($valid, $rules)->passes());
        foreach ([
            ['signed_at' => '2999-01-01'],
            ['signed_at' => ''],
            ['document_version_id' => 0],
            ['lock_version' => -1],
            ['authority_confirmed' => false],
            ['storage_location' => str_repeat('Я', 2001)],
            ['signers' => [['kind' => 'user', 'name' => 'Иван']]],
            ['signers' => [['kind' => 'manual', 'name' => str_repeat('Я', 256)]]],
            ['signers' => [['kind' => 'manual', 'name' => 'Иван', 'position' => str_repeat('Я', 256)]]],
            ['signers' => [['kind' => 'manual', 'name' => 'Иван', 'authority_basis' => str_repeat('Я', 513)]]],
        ] as $invalid) {
            self::assertTrue($factory->make(array_replace($valid, $invalid), $rules)->fails());
        }
    }
}
