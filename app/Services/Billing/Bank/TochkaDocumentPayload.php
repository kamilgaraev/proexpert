<?php

declare(strict_types=1);

namespace App\Services\Billing\Bank;

use App\Models\CommercialBankDocument;
use InvalidArgumentException;

final class TochkaDocumentPayload
{
    public function build(CommercialBankDocument $document, ?string $parentId = null): array
    {
        $snapshot = $document->snapshot;
        $buyer = $snapshot['buyer'];
        $amount = (int) ($snapshot['amount_minor'] ?? 0);
        $account = ($snapshot['bank']['account'] ?? '').'/'.($snapshot['bank']['bik'] ?? '');
        if (! in_array($document->kind, ['invoice', 'closing'], true) || $amount <= 0
            || preg_match('/\A\d{20}\/\d{9}\z/', $account) !== 1
            || ! in_array($buyer['type'] ?? '', ['company', 'ip'], true)
            || preg_match('/\A(?:\d{10}|\d{12})\z/', (string) ($buyer['inn'] ?? '')) !== 1
            || trim((string) ($buyer['name'] ?? '')) === '' || trim((string) ($buyer['address'] ?? '')) === ''
            || ($snapshot['tax'] ?? '') !== 'without_vat') {
            throw new InvalidArgumentException('bank_document_snapshot_invalid');
        }
        $rubles = (float) BankMoney::decimal($amount);
        $description = (string) $snapshot['description'];
        if ($document->kind === 'closing') {
            $description .= '. '.trans_message('bank_billing.performance_confirmed');
        }
        if (! empty($snapshot['period_start_at']) && ! empty($snapshot['period_end_at'])) {
            $description .= ' '.trans_message('bank_billing.document_period', [
                'start' => \Carbon\CarbonImmutable::parse($snapshot['period_start_at'])->format('d.m.Y'),
                'end' => \Carbon\CarbonImmutable::parse($snapshot['period_end_at'])->format('d.m.Y'),
            ]);
        }
        $content = [
            'Positions' => [['positionName' => $description, 'unitCode' => 'усл.ед.', 'ndsKind' => 'without_nds',
                'price' => $rubles, 'quantity' => 1, 'totalAmount' => $rubles, 'totalNds' => 0]],
            'totalAmount' => $rubles, 'totalNds' => 0,
            'number' => $document->kind === 'invoice' ? $document->invoice->number : $document->invoice->number.'-ACT',
            'date' => $document->issued_at->setTimezone('Europe/Moscow')->toDateString(),
            'basedOn' => trans_message('bank_billing.document_basis', ['number' => $document->invoice->number,
                'version' => (string) ($snapshot['legal_version'] ?? '')]),
        ];
        if ($document->kind === 'invoice') {
            $content['comment'] = (string) $snapshot['purpose'];
            $content['paymentExpiryDate'] = $document->invoice->expires_at->setTimezone('Europe/Moscow')->toDateString();
        }
        $data = ['accountId' => $account,
            'customerCode' => $document->provider_customer_code ?: (string) config('bank_billing.customer_code'),
            'SecondSide' => ['taxCode' => $buyer['inn'], 'type' => $buyer['type'],
                'secondSideName' => $buyer['name'], 'legalAddress' => $buyer['address']],
            'Content' => [$document->kind === 'invoice' ? 'Invoice' : 'Act' => $content]];
        if (trim($data['customerCode']) === '') {
            throw new InvalidArgumentException('bank_document_customer_invalid');
        }
        if ($document->kind === 'closing' && $parentId !== null) {
            $data['documentId'] = $parentId;
        }

        return ['Data' => $data];
    }
}
