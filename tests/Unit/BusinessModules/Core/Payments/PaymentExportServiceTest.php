<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentExportService;
use App\Models\Contractor;
use App\Models\Organization;
use Illuminate\Support\Collection;
use Tests\TestCase;

class PaymentExportServiceTest extends TestCase
{
    public function test_one_c_export_contains_payment_parties_bank_details_and_footer(): void
    {
        $document = new PaymentDocument([
            'document_number' => 'C4-202604-0001',
            'document_date' => '2026-04-25',
            'amount' => 6000000,
            'bank_account' => '40702810900000000002',
            'bank_bik' => '044525225',
            'bank_correspondent_account' => '30101810400000000225',
            'bank_name' => 'ПАО Банк Получателя',
            'payment_purpose' => '',
            'description' => 'Оплата по договору N 15',
        ]);

        $document->setRelation('payerOrganization', new Organization([
            'name' => 'ООО МОСТ',
            'tax_number' => '7701234567',
            'registration_number' => '770101001',
        ]));

        $document->setRelation('payeeContractor', new Contractor([
            'name' => 'МТМ СТРОЙ',
            'inn' => '7812345678',
            'kpp' => '781201001',
            'bank_details' => 'Банк: ПАО Банк Подрядчика, БИК 044525999, р/с 40702810900000000001, к/с 30101810400000000999',
        ]));

        $path = (new PaymentExportService())->exportPaymentRegistry1C(new Collection([$document]));

        try {
            $content = file_get_contents($path);
            $this->assertIsString($content);

            $decoded = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');

            $this->assertStringContainsString('1CClientBankExchange', $decoded);
            $this->assertStringContainsString('Кодировка=Windows', $decoded);
            $this->assertStringContainsString('СекцияДокумент=Платежное поручение', $decoded);
            $this->assertStringContainsString('Плательщик=ООО МОСТ', $decoded);
            $this->assertStringContainsString('ПлательщикИНН=7701234567', $decoded);
            $this->assertStringContainsString('Получатель=МТМ СТРОЙ', $decoded);
            $this->assertStringContainsString('ПолучательИНН=7812345678', $decoded);
            $this->assertStringContainsString('ПолучательСчет=40702810900000000002', $decoded);
            $this->assertStringContainsString('ПолучательБИК=044525225', $decoded);
            $this->assertStringContainsString('НазначениеПлатежа=Оплата по договору N 15', $decoded);
            $this->assertStringContainsString('КонецФайла', $decoded);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
