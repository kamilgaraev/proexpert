<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Http\Requests\Api\V1\Admin\LegalArchive\RegisterLegalArchivePaperOriginalRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

final class LegalSignatureSignerRequestTest extends TestCase
{
    public function test_party_name_and_registration_pass_without_relaxing_manual_limits(): void
    {
        $factory = new Factory(new Translator(new ArrayLoader, 'ru'));
        $party = ['kind' => 'party', 'party_id' => 1, 'party_role' => 'other', 'name' => str_repeat('Д', 512), 'registration_number' => '0001234567890'];
        $payload = ['lock_version' => 0, 'document_version_id' => 1, 'idempotency_key' => 'key', 'signed_at' => '2026-01-01',
            'storage_location' => 'Архив', 'authority_confirmed' => true, 'signers' => [$party]];
        $request = RegisterLegalArchivePaperOriginalRequest::create('/', 'POST', $payload);
        $validator = $factory->make($payload, $request->rules());
        self::assertFalse($validator->fails(), json_encode($validator->errors()->all(), JSON_UNESCAPED_UNICODE));
        self::assertEquals($party, $validator->validated()['signers'][0]);
        self::assertSame('0001234567890', $validator->validated()['signers'][0]['registration_number']);
        $payload['signers'] = [['kind' => 'manual', 'name' => str_repeat('Д', 256)]];
        $request = RegisterLegalArchivePaperOriginalRequest::create('/', 'POST', $payload);
        $validator = $factory->make($payload, $request->rules());
        self::assertTrue($validator->fails());
        self::assertTrue($validator->errors()->has('signers.0.name'));
    }
}
