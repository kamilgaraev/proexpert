<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Legal\LegalDocumentService;

final class LegalAcceptanceFixture
{
    public static function enable(): void
    {
        config([
            'legal.reviewed' => true, 'legal.commercial_enabled' => true, 'legal.analytics_reviewed' => true,
            'legal.provider' => ['name' => 'Тестовый поставщик', 'status' => 'ИП', 'inn' => '000000000000',
                'registration_number' => '000000000000000', 'address' => 'Тестовый адрес', 'email' => 'legal@example.test',
                'bank_details' => 'Тестовые реквизиты', 'tax_status' => 'Тестовый режим'],
            'legal.subprocessors' => [['name' => 'Тестовый обработчик', 'address' => 'Тестовый адрес', 'country' => 'Россия', 'purpose' => 'Тест', 'data' => 'Тестовые данные', 'role' => 'Обработчик']],
        ]);
    }

    public static function payload(array $keys = ['offer', 'processing', 'privacy', 'renewal']): array
    {
        $hashes = [];
        foreach ($keys as $key) {
            $hashes[$key] = app(LegalDocumentService::class)->hash($key);
        }

        return ['legal_documents' => $hashes, 'terms_accepted' => true, 'privacy_accepted' => true,
            'processing_accepted' => true, 'representative_authority' => true, 'account_rules_accepted' => true];
    }
}
