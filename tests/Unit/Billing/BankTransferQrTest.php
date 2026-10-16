<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Models\CommercialBankInvoice;
use App\Services\Billing\Bank\BankBillingAccess;
use App\Services\Billing\Bank\BankTransferQrService;
use chillerlan\QRCode\QRCode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class BankTransferQrTest extends TestCase
{
    private function invoice(array $attributes = []): CommercialBankInvoice
    {
        return (new CommercialBankInvoice)->setDateFormat('Y-m-d H:i:s')->forceFill(array_replace([
            'public_id' => '11111111-1111-4111-8111-111111111111', 'currency' => 'RUB',
            'amount_minor' => 7990000, 'paid_minor' => 17000, 'status' => 'partially_paid',
            'expires_at' => new \DateTimeImmutable('+1 day'),
            'snapshot' => ['amount_minor' => 7990000,
                'seller' => ['name' => 'ИП Тестовый', 'inn' => '500100732259'],
                'bank' => ['account' => '40802810000000000001', 'bik' => '044525104',
                    'name' => 'Тестовый банк', 'correspondent_account' => '30101810000000000001'],
                'purpose' => 'Оплата по счёту MOST-0000000040. Без НДС.'],
        ], $attributes));
    }

    public function test_png_decodes_to_exact_snapshot_requisites_purpose_and_remaining_kopecks(): void
    {
        $invoice = $this->invoice();
        $payment = (new BankTransferQrService(new BankBillingAccess))->forInvoice($invoice);
        $this->assertSame('bank_transfer', $payment['type']);
        $this->assertSame($invoice->public_id, $payment['invoice_id']);
        $this->assertSame(7973000, $payment['amount_minor']);
        $this->assertStringStartsWith('data:image/png;base64,', $payment['image']);
        $image = base64_decode(substr($payment['image'], strlen('data:image/png;base64,')), true);
        $this->assertNotFalse($image);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $image);
        $decoded = (new QRCode)->readFromBlob($image)->data;
        $this->assertSame('ST00012|Name=ИП Тестовый|PersonalAcc=40802810000000000001'
            .'|BankName=Тестовый банк|BIC=044525104|CorrespAcc=30101810000000000001'
            .'|PayeeINN=500100732259|Sum=7973000|Purpose=Оплата по счёту MOST-0000000040. Без НДС.', $decoded);
        $this->assertSame($payment['payload'], $decoded);
        $this->assertFalse($invoice->exists);
    }

    public function test_closed_expired_paid_and_foreign_currency_invoices_have_no_payment_qr(): void
    {
        $service = new BankTransferQrService(new BankBillingAccess);
        foreach ([['status' => 'paid'], ['status' => 'canceled'], ['status' => 'refunded'],
            ['status' => 'expired'], ['status' => 'review'], ['currency' => 'USD'],
            ['paid_minor' => 7990000], ['paid_minor' => 8000000], ['paid_minor' => -1],
            ['paid_at' => new \DateTimeImmutable], ['expires_at' => new \DateTimeImmutable('-1 second')],
            ['expires_at' => null]] as $attributes) {
            $invoice = $this->invoice($attributes);
            $this->assertFalse($service->payable($invoice));
            try {
                $service->forInvoice($invoice);
                $this->fail('Inactive invoice QR accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_qr_rejects_field_injection_and_invalid_bank_requisites(): void
    {
        $service = new BankTransferQrService(new BankBillingAccess);
        foreach (['purpose' => 'Оплата|Sum=1', 'bank.bik' => '1234', 'bank.account' => '1234',
            'bank.correspondent_account' => '1234', 'bank.name' => str_repeat('б', 46),
            'seller.name' => "ИП\nТестовый", 'seller.inn' => '1234', 'amount_minor' => 1] as $path => $value) {
            $invoice = $this->invoice();
            $snapshot = $invoice->snapshot;
            $parts = explode('.', $path);
            if (count($parts) === 2) {
                $snapshot[$parts[0]][$parts[1]] = $value;
            } else {
                $snapshot[$path] = $value;
            }
            $invoice->snapshot = $snapshot;
            $this->assertFalse($service->payable($invoice));
            try {
                $service->forInvoice($invoice);
                $this->fail('Unsafe QR field accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
